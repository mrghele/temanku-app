package absensi

// Absensi sungguhan dengan wajah. Satu alur yang diputuskan SERVER:
//   1. aturan murah dulu (pola kerja & jendela waktunya, libur, cuti/dinas, lokasi, sudah absen?)
//      — bila gagal, wajah tidak diproses sehingga CPU Face Engine tidak terbuang;
//   2. verifikasi wajah + liveness oleh Face Engine;
//   3. dicatat ke APEL per (nip, tanggal dinas, pola).
// Pola kerja: 5/6 hari kerja (jadwal APEL), shift pagi/siang/malam (malam berganti tanggal:
// dicatat pada TANGGAL DINAS = tanggal shift dimulai), oncall (24 jam), lepas jaga (sekali, di mana
// saja). Lupa absen masuk: absen pulang tetap bisa, ditandai tanpa_masuk. Cuti / dinas luar menutup
// absen; Dinas DALAM: sekali absen di lokasi kegiatan (surat tugas APEL), sekaligus absen masuk.
// WFA: Kamis/Jumat di wilayah Kab. Selayar. Upacara: sesuai jadwal, sekaligus absen masuk.

import (
	"crypto/rand"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"net/http"
	"os"
	"sort"
	"strings"
	"sync"
	"time"

	"temanku/db"

	"github.com/gin-gonic/gin"
)

// ---------------------------------------------------------------------------
// Wilayah WFA: batas desa/kelurahan Kab. Kepulauan Selayar (GeoJSON).

type desaArea struct {
	Nama, Kecamatan string
	Polygons        [][][]geoPoint // tiap poligon: ring luar, lalu lubang
	MinLat, MaxLat  float64
	MinLng, MaxLng  float64
}

var (
	wfaOnce  sync.Once
	wfaAreas []desaArea
	wfaErr   error
)

func wfaGeoJSONPath() string {
	if path := strings.TrimSpace(os.Getenv("FACE_WFA_GEOJSON")); path != "" {
		return path
	}
	return "/etc/temanku/desa-selayar.geojson"
}

func parseDesaGeoJSON(raw []byte) ([]desaArea, error) {
	var doc struct {
		Features []struct {
			Properties map[string]interface{} `json:"properties"`
			Geometry   struct {
				Type        string          `json:"type"`
				Coordinates json.RawMessage `json:"coordinates"`
			} `json:"geometry"`
		} `json:"features"`
	}
	if err := json.Unmarshal(raw, &doc); err != nil {
		return nil, err
	}
	toRing := func(points [][]float64) []geoPoint {
		ring := make([]geoPoint, 0, len(points))
		for _, p := range points {
			if len(p) >= 2 {
				ring = append(ring, geoPoint{Lat: p[1], Lng: p[0]})
			}
		}
		return ring
	}
	areas := make([]desaArea, 0, len(doc.Features))
	for _, feature := range doc.Features {
		var polygons [][][]geoPoint
		switch feature.Geometry.Type {
		case "Polygon":
			var rings [][][]float64
			if json.Unmarshal(feature.Geometry.Coordinates, &rings) != nil {
				continue
			}
			polygon := [][]geoPoint{}
			for _, r := range rings {
				polygon = append(polygon, toRing(r))
			}
			polygons = append(polygons, polygon)
		case "MultiPolygon":
			var multi [][][][]float64
			if json.Unmarshal(feature.Geometry.Coordinates, &multi) != nil {
				continue
			}
			for _, rings := range multi {
				polygon := [][]geoPoint{}
				for _, r := range rings {
					polygon = append(polygon, toRing(r))
				}
				polygons = append(polygons, polygon)
			}
		default:
			continue
		}
		area := desaArea{Polygons: polygons, MinLat: 90, MaxLat: -90, MinLng: 180, MaxLng: -180}
		area.Nama, _ = feature.Properties["NAMOBJ"].(string)
		area.Kecamatan, _ = feature.Properties["WADMKC"].(string)
		for _, polygon := range polygons {
			if len(polygon) == 0 {
				continue
			}
			for _, p := range polygon[0] {
				area.MinLat, area.MaxLat = math.Min(area.MinLat, p.Lat), math.Max(area.MaxLat, p.Lat)
				area.MinLng, area.MaxLng = math.Min(area.MinLng, p.Lng), math.Max(area.MaxLng, p.Lng)
			}
		}
		if len(polygons) > 0 && area.MaxLat >= area.MinLat {
			areas = append(areas, area)
		}
	}
	if len(areas) == 0 {
		return nil, errors.New("GeoJSON tidak berisi batas desa")
	}
	return areas, nil
}

// wfaDesaAt: desa tempat titik berada (ring luar memuat titik dan tidak ada lubang yang memuatnya).
var wfaDesaAt = func(p geoPoint) (desaArea, bool, error) {
	wfaOnce.Do(func() {
		raw, err := os.ReadFile(wfaGeoJSONPath())
		if err != nil {
			wfaErr = err
			return
		}
		wfaAreas, wfaErr = parseDesaGeoJSON(raw)
	})
	if wfaErr != nil {
		return desaArea{}, false, wfaErr
	}
	return findDesa(wfaAreas, p)
}

func findDesa(areas []desaArea, p geoPoint) (desaArea, bool, error) {
	for _, area := range areas {
		if p.Lat < area.MinLat || p.Lat > area.MaxLat || p.Lng < area.MinLng || p.Lng > area.MaxLng {
			continue
		}
		for _, polygon := range area.Polygons {
			if len(polygon) == 0 || !insidePolygon(p, polygon[0]) {
				continue
			}
			inHole := false
			for _, hole := range polygon[1:] {
				if insidePolygon(p, hole) {
					inHole = true
					break
				}
			}
			if !inHole {
				return area, true, nil
			}
		}
	}
	return desaArea{}, false, nil
}

// ---------------------------------------------------------------------------
// Mesin aturan: pilihan absen yang AKTIF saat ini. Dipakai untuk tombol (status) DAN validasi
// saat absen dikirim, sehingga tampilan & server selalu sama.

type absenOpsi struct {
	Aksi    string `json:"aksi"` // masuk | pulang | wfa | upacara | dinas
	Pola    string `json:"pola,omitempty"`
	Label   string `json:"label"`
	Tanggal string `json:"tanggal_dinas"`
	Aktif   bool   `json:"aktif"`
	Alasan  string `json:"alasan,omitempty"`
	Mode    string `json:"mode,omitempty"` // pulang: buka (sudah masuk) | lupa (lupa absen masuk)
	// Keterangan lokasi bila tombol aktif (mis. "Di dalam area unit kerja: 40 m dari titik ...").
	LokasiInfo string `json:"lokasi,omitempty"`
	Jarak      *int   `json:"jarak_m,omitempty"` // meter ke area tujuan (bila diperiksa)
	Jadwal     string `json:"jadwal,omitempty"`  // masuk shift: sesuai | beda (jadwal shift APEL)
	// Sesuai jadwal sebelum lokasi diperiksa: dipakai tombol "Absen Sekarang" (menu presensi) tanpa membaca GPS.
	JadwalAktif bool `json:"jadwal_aktif"`

	pola    polaKerja
	row     *absenRow
	target  time.Time // jam masuk (telat) / jam selesai (pulang cepat)
	upacara *upacaraJadwal
	lokasi  string
}

type absenCtx struct {
	now                  time.Time
	day, yesterday, hari string
	nip, pnsID, unit     string
	all                  map[string]polaKerja
	polas                []polaKerja
	rows                 []absenRow
	libur                bool
	liburNama            string
	lain                 absenLainInfo
	adaLain              bool
	metode               string      // LOKASI | WAJAH (metode absensi unit, berjenjang ke unit induk)
	fwa                  bool        // boleh FWA Kamis/Jumat (Dinas/Badan/Kantor/Sekretariat/Inspektorat)
	dinas                dinasLokasi // surat tugas hari ini (Dinas DALAM / LUAR)
	upacara              upacaraAturan
}

func clockAt(day, hhmmss string, plusDays int) time.Time {
	return parseWITA(day+" "+hhmmss).AddDate(0, 0, plusDays)
}

func hhmm(t time.Time) string { return t.Format("15:04") }

// tglJam: "08:00" bila hari yang sama dengan ref, selain itu "Sel 08:00".
func tglJam(t, ref time.Time) string {
	if t.Format("2006-01-02") == ref.Format("2006-01-02") {
		return hhmm(t)
	}
	return namaHari[t.Weekday()][:3] + " " + hhmm(t)
}

func tglDinasLabel(day string) string {
	t := parseWITA(day + " 00:00:00")
	return fmt.Sprintf("%s %02d/%02d", namaHari[t.Weekday()][:3], t.Day(), int(t.Month()))
}

func (p polaKerja) start(day string) time.Time { return clockAt(day, p.Mulai, 0) }
func (p polaKerja) end(day string) time.Time {
	plus := 0
	if p.Lintas {
		plus = 1
	}
	return clockAt(day, p.Selesai, plus)
}

func loadAbsenCtx(nip, pnsID, unit string, now time.Time) (absenCtx, error) {
	ctx := absenCtx{now: now, day: now.Format("2006-01-02"), yesterday: now.AddDate(0, 0, -1).Format("2006-01-02"), hari: namaHari[now.Weekday()],
		nip: nip, pnsID: pnsID, unit: unit}
	var err error
	if ctx.all, err = absenData.polaAll(); err != nil || len(ctx.all) == 0 {
		return ctx, errors.New("pola kerja belum tersedia di APEL")
	}
	codes, err := polaUnitBerjenjang(unit)
	if err != nil {
		return ctx, err
	}
	ctx.metode = metodeAbsen(unit)
	ctx.fwa = fwaBoleh(unit)
	ctx.upacara = aturanUpacara(nip, unit)
	if len(codes) == 0 {
		codes = []string{"5HK"} // unit tanpa pengaturan: 5 hari kerja
	}
	for _, code := range codes {
		if p, ok := ctx.all[code]; ok {
			ctx.polas = append(ctx.polas, p)
		}
	}
	if ctx.rows, err = absenData.rows(nip, ctx.yesterday, ctx.day); err != nil {
		return ctx, err
	}
	if ctx.liburNama, ctx.libur, err = simulasiData.holiday(ctx.day); err != nil {
		return ctx, err
	}
	if ctx.lain, ctx.adaLain, err = absenData.absenLain(pnsID, ctx.day); err != nil {
		return ctx, err
	}
	if ctx.adaLain && ctx.lain.Jenis != "CUTI" && ctx.lain.RefUUID != "" {
		ctx.dinas, _ = absenData.dinasLokasi(ctx.lain.RefUUID)
	}
	return ctx, nil
}

func (ctx absenCtx) row(day, pola string) *absenRow {
	for i := range ctx.rows {
		if ctx.rows[i].Day == day && ctx.rows[i].Pola == pola {
			return &ctx.rows[i]
		}
	}
	return nil
}

func (ctx absenCtx) regulerRow(day string) *absenRow {
	for i := range ctx.rows {
		if p, ok := ctx.all[ctx.rows[i].Pola]; ok && p.reguler() && ctx.rows[i].Day == day {
			return &ctx.rows[i]
		}
	}
	return nil
}

func (ctx absenCtx) regulerPola() (polaKerja, bool) {
	for _, p := range ctx.polas {
		if p.reguler() {
			return p, true
		}
	}
	return polaKerja{}, false
}

func (ctx absenCtx) lainAlasan() string {
	if !ctx.adaLain {
		return ""
	}
	switch ctx.lain.Jenis {
	case "CUTI":
		return "Anda tercatat CUTI hari ini"
	case "LUAR":
		if ctx.hariPerjalanan() {
			return "Anda tercatat DINAS LUAR hari ini (hari perjalanan: tidak perlu absen)"
		}
		return "Anda tercatat DINAS LUAR hari ini — gunakan Absen Dinas Luar"
	default:
		return "Anda tercatat DINAS DALAM hari ini — gunakan Absen Dinas"
	}
}

// hariPerjalanan: hari berangkat & hari kembali Dinas LUAR dibebaskan dari absen.
func (ctx absenCtx) hariPerjalanan() bool {
	return ctx.dinas.Awal == "" || ctx.day == ctx.dinas.Awal || ctx.day == ctx.dinas.Akhir
}

func (ctx absenCtx) masukOpsi() []absenOpsi {
	var list []absenOpsi
	for _, p := range ctx.polas {
		o := absenOpsi{Aksi: "masuk", Pola: p.Kode, Label: "Masuk · " + p.Nama, Tanggal: ctx.day, pola: p}
		if p.Kode == "LEPAS" || !p.WajibPulang {
			o.Label = p.Nama + " (sekali absen, di mana saja)"
		}
		existing := ctx.row(ctx.day, p.Kode)
		switch {
		case ctx.adaLain:
			o.Alasan = ctx.lainAlasan()
		case p.Bebas && !p.WajibPulang: // lepas jaga: kapan saja
			if existing != nil {
				o.Alasan = "Sudah tercatat pukul " + hhmm(existing.In)
			} else {
				o.Aktif = true
			}
		case p.Bebas: // oncall: kapan saja, satu yang terbuka
			open := false
			for _, r := range ctx.rows {
				if r.Pola == p.Kode && r.Out.IsZero() && ctx.now.Sub(r.In) <= 24*time.Hour {
					open = true
					o.Alasan = "Oncall masih berlangsung sejak " + tglJam(r.In, ctx.now) + " — absen pulang dulu"
				}
			}
			if !open && existing != nil {
				o.Alasan = "Oncall hari ini sudah tercatat"
			} else if !open {
				o.Aktif = true
			}
		case p.reguler():
			j, err := ctx.jadwalReguler(p.HariKerja, "masuk")
			reg := ctx.regulerRow(ctx.day)
			switch {
			case ctx.libur:
				o.Alasan = "Hari ini " + firstNonEmpty(ctx.liburNama, "hari libur")
			case reg != nil:
				o.Alasan = "Sudah absen masuk pukul " + hhmm(reg.In)
			case err != nil:
				o.Alasan = "Jadwal APEL tidak dapat diperiksa"
			case !j.Found:
				o.Alasan = "Tidak ada jadwal " + p.Nama + " hari " + ctx.hari
			default:
				open, closeAt := clockAt(ctx.day, j.Begin, 0), clockAt(ctx.day, j.End, 0)
				o.target = clockAt(ctx.day, firstNonEmpty(j.Masuk, j.Begin), 0)
				switch {
				case ctx.now.Before(open):
					o.Alasan = "Dibuka pukul " + hhmm(open)
				case ctx.now.After(closeAt):
					o.Alasan = "Ditutup pukul " + hhmm(closeAt)
				default:
					o.Aktif = true
				}
			}
		default: // shift berjam
			start := p.start(ctx.day)
			open, closeAt := start.Add(-time.Duration(p.BukaSebelum)*time.Minute), start.Add(time.Duration(p.MasukMaks)*time.Minute)
			o.target = start
			switch {
			case existing != nil:
				o.Alasan = "Sudah absen masuk pukul " + hhmm(existing.In)
			case ctx.now.Before(open):
				o.Alasan = "Dibuka pukul " + hhmm(open)
			case ctx.now.After(closeAt):
				o.Alasan = "Ditutup pukul " + hhmm(closeAt)
			default:
				o.Aktif = true
			}
		}
		list = append(list, o)
	}
	return list
}

func (ctx absenCtx) pulangOpsi() []absenOpsi {
	var list []absenOpsi
	// 1) Catatan yang sudah masuk & belum pulang (termasuk shift malam yang dimulai kemarin).
	for i := range ctx.rows {
		r := &ctx.rows[i]
		p, ok := ctx.all[r.Pola]
		if !ok || !r.Out.IsZero() || !p.WajibPulang || r.Jenis == "dinas" || r.Jenis == "dinasluar" {
			continue
		}
		o := absenOpsi{Aksi: "pulang", Pola: p.Kode, Label: "Pulang · " + p.Nama, Tanggal: r.Day, Mode: "buka", pola: p, row: r}
		if r.Day != ctx.day {
			o.Label += " (tgl dinas " + tglDinasLabel(r.Day) + ")"
		}
		switch {
		case p.Bebas: // oncall: maks 24 jam sejak masuk
			if ctx.now.Sub(r.In) > 24*time.Hour {
				continue
			}
			o.Aktif = true
		case p.reguler():
			if r.Day != ctx.day {
				continue // pulang reguler kemarin sudah lewat
			}
			j, err := ctx.jadwalReguler(p.HariKerja, "pulang")
			if err != nil || !j.Found {
				o.Alasan = "Jadwal pulang tidak tersedia"
				break
			}
			open, closeAt := clockAt(ctx.day, j.Begin, 0), clockAt(ctx.day, j.End, 0)
			o.target = open
			switch {
			case ctx.now.Before(open):
				o.Alasan = "Dibuka pukul " + hhmm(open)
			case ctx.now.After(closeAt):
				o.Alasan = "Ditutup pukul " + hhmm(closeAt)
			default:
				o.Aktif = true
			}
		default: // shift berjam: sejak jam mulai sampai (selesai + tutup)
			end := p.end(r.Day)
			closeAt := end.Add(time.Duration(p.TutupSetelah) * time.Minute)
			if ctx.now.After(closeAt) {
				continue // kedaluwarsa: tercatat tidak absen pulang
			}
			o.target, o.Aktif = end, true
		}
		if o.Aktif && r.Jenis == "wfa" && absenData.laporanCount(ctx.nip, r.Day) == 0 {
			o.Aktif, o.Alasan = false, "Isi laporan harian FWA terlebih dahulu (minimal 1 kegiatan + URL bukti)"
		}
		list = append(list, o)
	}
	// 2) Lupa absen masuk: hanya pola yang jam selesainya sedang berlaku.
	if !ctx.adaLain {
		for _, p := range ctx.polas {
			if !p.WajibPulang || p.Bebas {
				continue
			}
			if p.reguler() {
				if ctx.libur || ctx.regulerRow(ctx.day) != nil {
					continue
				}
				j, err := ctx.jadwalReguler(p.HariKerja, "pulang")
				if err != nil || !j.Found {
					continue
				}
				open, closeAt := clockAt(ctx.day, j.Begin, 0), clockAt(ctx.day, j.End, 0)
				if !ctx.now.Before(open) && !ctx.now.After(closeAt) {
					list = append(list, absenOpsi{Aksi: "pulang", Pola: p.Kode, Label: "Pulang · " + p.Nama + " (lupa absen masuk)",
						Tanggal: ctx.day, Mode: "lupa", Aktif: true, pola: p, target: open})
				}
				continue
			}
			for _, day := range []string{ctx.yesterday, ctx.day} {
				if day == ctx.yesterday && !p.Lintas {
					continue
				}
				end := p.end(day)
				open := end.Add(-time.Duration(p.PulangSebelum) * time.Minute)
				closeAt := end.Add(time.Duration(p.TutupSetelah) * time.Minute)
				if ctx.now.Before(open) || ctx.now.After(closeAt) || ctx.row(day, p.Kode) != nil {
					continue
				}
				label := "Pulang · " + p.Nama + " (lupa absen masuk)"
				if day != ctx.day {
					label += " — tgl dinas " + tglDinasLabel(day)
				}
				list = append(list, absenOpsi{Aksi: "pulang", Pola: p.Kode, Label: label, Tanggal: day, Mode: "lupa", Aktif: true, pola: p, target: end})
			}
		}
	}
	return list
}

func (ctx absenCtx) khususOpsi() []absenOpsi {
	var list []absenOpsi
	reg, hasReg := ctx.regulerPola()
	pola := "5HK"
	if hasReg {
		pola = reg.Kode
	}
	// Dinas DALAM: sekali absen di lokasi kegiatan, sekaligus absen masuk.
	if ctx.adaLain && ctx.lain.Jenis == "DALAM" {
		o := absenOpsi{Aksi: "dinas", Pola: pola, Label: "Absen Dinas Dalam", Tanggal: ctx.day}
		if l, err := absenData.dinasLokasi(ctx.lain.RefUUID); err == nil && l.Tempat != "" {
			o.Label += " — " + l.Tempat
		}
		if r := ctx.row(ctx.day, pola); r != nil {
			o.Alasan = "Sudah tercatat pukul " + hhmm(r.In)
		} else {
			o.Aktif = true
		}
		list = append(list, o)
	}
	// Dinas LUAR: bukti kehadiran sekali sehari di kab/kota tujuan (kecuali hari berangkat & kembali).
	if ctx.adaLain && ctx.lain.Jenis == "LUAR" && !ctx.hariPerjalanan() {
		o := absenOpsi{Aksi: "dinasluar", Pola: pola, Label: "Absen Dinas Luar", Tanggal: ctx.day}
		if nama := kabkotaNama(ctx.dinas.TujuanKabKota); nama != "" {
			o.Label += " — " + nama
		} else if ctx.dinas.Tempat != "" {
			o.Label += " — " + ctx.dinas.Tempat
		}
		if r := ctx.row(ctx.day, pola); r != nil {
			o.Alasan = "Sudah tercatat pukul " + hhmm(r.In)
		} else {
			o.Aktif = true
		}
		list = append(list, o)
	}
	// WFA (FWA): Kamis/Jumat, unit berhak FWA, pegawai reguler, jam masuk reguler.
	if hasReg && ctx.fwa {
		o := absenOpsi{Aksi: "wfa", Pola: reg.Kode, Label: "Masuk WFA (wilayah Selayar)", Tanggal: ctx.day, pola: reg}
		j, err := ctx.jadwalReguler(reg.HariKerja, "masuk")
		switch {
		case !isWFADay(ctx.now):
			o.Alasan = "WFA hanya hari Kamis dan Jumat"
		case ctx.adaLain:
			o.Alasan = ctx.lainAlasan()
		case ctx.libur:
			o.Alasan = "Hari ini " + firstNonEmpty(ctx.liburNama, "hari libur")
		case ctx.regulerRow(ctx.day) != nil:
			o.Alasan = "Sudah absen masuk hari ini"
		case err != nil || !j.Found:
			o.Alasan = "Tidak ada jadwal masuk hari " + ctx.hari
		case ctx.now.Before(clockAt(ctx.day, j.Begin, 0)):
			o.Alasan = "Dibuka pukul " + jam(j.Begin)
		case ctx.now.After(clockAt(ctx.day, j.End, 0)):
			o.Alasan = "Ditutup pukul " + jam(j.End)
		default:
			o.Aktif, o.target = true, clockAt(ctx.day, firstNonEmpty(j.Masuk, j.Begin), 0)
		}
		if isWFADay(ctx.now) {
			list = append(list, o)
		}
	}
	// Upacara: hanya bila ada jadwal hari ini (boleh hari libur); sekaligus absen masuk.
	jadwal, _ := absenData.upacaraHariIni(ctx.day)
	muatPesertaJadwal(jadwal)
	rantaiUnit := absenData.unitChain(ctx.unit)
	clock := ctx.now.Format("15:04:05")
	for i := range jadwal {
		j := &jadwal[i]
		ikut, khusus := ikutJadwal(j, ctx.nip, rantaiUnit, ctx.upacara)
		if !ikut {
			continue // bukan peserta: aturan unit (Senin/tgl 17) atau tidak dipilih untuk jadwal ini
		}
		j.Khusus = khusus
		o := absenOpsi{Aksi: "upacara", Pola: pola, Label: "Absen " + j.Nama + " (" + jam(j.Buka) + "–" + jam(j.Tutup) + ")", Tanggal: ctx.day, upacara: j}
		_, done, _ := absenData.upacaraSudah(j.ID, ctx.nip)
		switch {
		case ctx.adaLain && ctx.lain.Jenis != "DALAM":
			o.Alasan = ctx.lainAlasan()
		case done:
			o.Alasan = "Kehadiran sudah tercatat"
		case clock < j.Buka:
			o.Alasan = "Dibuka pukul " + jam(j.Buka)
		case clock > j.Tutup:
			o.Alasan = "Ditutup pukul " + jam(j.Tutup)
		default:
			o.Aktif = true
		}
		list = append(list, o)
	}
	return list
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if strings.TrimSpace(v) != "" {
			return v
		}
	}
	return ""
}

// ---------------------------------------------------------------------------
// Handler: POST .../face/v2/absen/:action  (masuk | pulang | wfa | upacara | dinas | status)

var absenNama = map[string]string{"masuk": "MASUK", "pulang": "PULANG", "wfa": "MASUK WFA", "upacara": "UPACARA", "dinas": "DINAS DALAM", "dinasluar": "DINAS LUAR"}

func isWFADay(now time.Time) bool {
	return now.Weekday() == time.Thursday || now.Weekday() == time.Friday
}

type absenRequest struct {
	simulasiRequest
	Pola    string `json:"pola"`
	Tanggal string `json:"tanggal_dinas"`
}

func faceAbsen(c *gin.Context, nip, pnsID, unit, sessionMaterial, client string) {
	aksi := c.Param("action")
	now := simulasiData.now()
	if aksi == "status" {
		var lokasi simulasiRequest
		if body, ok := readFaceBody(c); ok {
			_ = json.Unmarshal(body, &lokasi)
		} else {
			return
		}
		absenStatus(c, nip, pnsID, unit, now, lokasi)
		return
	}
	if aksi == "laporan" { // laporan harian FWA
		laporanHarian(c, nip, now)
		return
	}
	if _, ok := absenNama[aksi]; !ok {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Jenis absen tidak dikenal"})
		return
	}
	body, ok := readFaceBody(c)
	if !ok {
		return
	}
	var req absenRequest
	if json.Unmarshal(body, &req) != nil || strings.TrimSpace(req.ChallengeID) == "" {
		c.JSON(http.StatusBadRequest, gin.H{"error": "JSON tidak valid"})
		return
	}
	body = nil
	waktu := fmt.Sprintf("%s, %d %s %d pukul %s WITA", namaHari[now.Weekday()], now.Day(), namaBulan[now.Month()], now.Year(), now.Format("15:04"))
	var checks []simulasiCheck
	add := func(key, label, status, message string) {
		checks = append(checks, simulasiCheck{key, label, status, message})
	}
	reply := func(recorded bool, message string) {
		c.Header("Cache-Control", "no-store")
		c.JSON(http.StatusOK, gin.H{"absen": true, "aksi": aksi, "pola": req.Pola, "tercatat": recorded, "waktu": waktu, "checks": checks, "pesan": message})
	}
	failNoFace := func(message string) {
		checks = append([]simulasiCheck{{"wajah", "Wajah & liveness", "lewati", "Belum diperiksa karena syarat lain belum terpenuhi"}}, checks...)
		reply(false, message)
	}

	ctx, err := loadAbsenCtx(nip, pnsID, unit, now)
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Data presensi tidak dapat diperiksa: " + err.Error()})
		return
	}
	// Kiriman ulang (sinyal lemah) dengan challenge yang sama setelah tercatat: berhasil, tidak diproses ulang.
	for _, r := range ctx.rows {
		if r.Challenge == req.ChallengeID || r.Checkout == req.ChallengeID {
			add("catatan", "Presensi", "ok", "Sudah tercatat ("+r.Pola+", tgl dinas "+r.Day+")")
			reply(true, "Presensi sudah tercatat.")
			return
		}
	}

	// Pilih opsi sesuai aksi & pola; harus AKTIF (sama dengan tombol di layar).
	var candidates []absenOpsi
	switch aksi {
	case "masuk":
		candidates = ctx.masukOpsi()
	case "pulang":
		candidates = ctx.pulangOpsi()
	default:
		for _, o := range ctx.khususOpsi() {
			if o.Aksi == aksi {
				candidates = append(candidates, o)
			}
		}
	}
	var active []absenOpsi
	var reason string
	for _, o := range candidates {
		if req.Pola != "" && o.Pola != req.Pola && aksi != "upacara" {
			continue
		}
		if req.Tanggal != "" && o.Tanggal != req.Tanggal {
			continue
		}
		if o.Aktif {
			active = append(active, o)
		} else if reason == "" {
			reason = o.Alasan
		}
	}
	if len(active) == 0 {
		if reason == "" {
			reason = "Pilihan absen ini tidak tersedia saat ini"
		}
		add("jadwal", "Jadwal", "gagal", reason)
		failNoFace(fmt.Sprintf("Presensi %s BELUM dicatat: %s.", absenNama[aksi], reason))
		return
	}
	if len(active) > 1 {
		names := []string{}
		for _, o := range active {
			names = append(names, o.Label)
		}
		add("jadwal", "Jadwal", "gagal", "Pilih salah satu: "+strings.Join(names, "; "))
		failNoFace("Presensi BELUM dicatat: pilih pola absen terlebih dahulu.")
		return
	}
	opsi := active[0]
	req.Pola = opsi.Pola
	add("jadwal", "Jadwal", "ok", opsi.Label+" · tgl dinas "+tglDinasLabel(opsi.Tanggal))

	// Catatan telat / pulang cepat (tidak menggagalkan).
	if !opsi.target.IsZero() {
		if (aksi == "masuk" || aksi == "wfa") && now.After(opsi.target) {
			add("telat", "Ketepatan waktu", "catatan", fmt.Sprintf("Terlambat %d menit (jam mulai %s)", int(now.Sub(opsi.target).Minutes()), hhmm(opsi.target)))
		}
		if aksi == "pulang" && now.Before(opsi.target) && opsi.Mode == "buka" {
			add("telat", "Ketepatan waktu", "catatan", fmt.Sprintf("Pulang lebih awal %d menit (jam selesai %s)", int(opsi.target.Sub(now).Minutes()), tglJam(opsi.target, now)))
		}
	}

	// Lokasi: pemeriksaan yang SAMA dengan tombol di layar (evaluasiLokasi).
	record := absenRecord{NIP: nip, Day: opsi.Tanggal, Pola: opsi.Pola, Challenge: req.ChallengeID, Client: client, At: now}
	if pos, gpsOK, _ := readAbsenLocation(req.simulasiRequest); gpsOK {
		record.Pos = pos
	}
	switch aksi {
	case "masuk":
		record.Jenis = map[bool]string{true: "kantor", false: "jaga"}[opsi.pola.reguler()]
	case "pulang":
		if opsi.row != nil {
			record.Jenis = opsi.row.Jenis
		} else {
			record.Jenis = map[bool]string{true: "kantor", false: "jaga"}[opsi.pola.reguler()]
		}
		record.TanpaMasuk = opsi.Mode == "lupa"
		if record.TanpaMasuk {
			add("masuk", "Absen masuk", "catatan", "Tidak ada absen masuk — tercatat TIDAK ABSEN MASUK")
		}
	default: // wfa | upacara | dinas
		record.Jenis = aksi
	}
	lok := evaluasiLokasi(ctx, opsi, req.simulasiRequest)
	if !lok.OK {
		add("lokasi", lok.Label, "gagal", lok.Pesan)
		failNoFace(fmt.Sprintf("Presensi %s BELUM dicatat: lokasi belum sesuai.", absenNama[aksi]))
		return
	}
	add("lokasi", lok.Label, "ok", lok.Pesan)
	record.Desa, record.Distance, opsi.lokasi = lok.Desa, lok.Distance, lok.Nama

	// Wajah + liveness (Face Engine) — hanya untuk unit bermetode LOKASI + WAJAH (diputuskan server).
	if ctx.metode != "WAJAH" {
		checks = append([]simulasiCheck{{"wajah", "Wajah", "lewati", "Unit Anda memakai absen lokasi (tanpa verifikasi wajah)"}}, checks...)
	} else {
		engineBody, _ := json.Marshal(map[string]interface{}{"challenge_id": req.ChallengeID, "nonce": req.Nonce, "frames": req.Frames})
		status, result, err := simulasiData.verify(c, nip, sessionMaterial, client, engineBody)
		engineBody = nil
		var engineError *faceEngineError
		switch {
		case err != nil && errors.As(err, &engineError) && engineError.status >= 500:
			c.JSON(engineError.status, gin.H{"error": engineError.message})
			return
		case err != nil:
			checks = append([]simulasiCheck{{"wajah", "Wajah & liveness", "gagal", "Verifikasi wajah belum dapat dilakukan"}}, checks...)
			reply(false, "Presensi BELUM dicatat: wajah belum terverifikasi.")
			return
		case status != http.StatusOK || result["matched"] != true:
			message, _ := result["error"].(string)
			checks = append([]simulasiCheck{{"wajah", "Wajah & liveness", "gagal", firstNonEmpty(message, "Wajah tidak cocok dengan wajah terdaftar")}}, checks...)
			reply(false, "Presensi BELUM dicatat: wajah belum terverifikasi.")
			return
		}
		checks = append([]simulasiCheck{{"wajah", "Wajah & liveness", "ok", "Wajah cocok; gerakan hidup terverifikasi"}}, checks...)
		record.Liveness = true
	}

	// Catat.
	var written bool
	switch {
	case aksi == "pulang" && opsi.Mode == "buka":
		written, err = absenData.updatePulang(record)
	case aksi == "upacara":
		written, err = absenData.insertUpacara(opsi.upacara.ID, opsi.lokasi, record)
		if err == nil && written && ctx.regulerRow(ctx.day) == nil {
			_, err = absenData.insert(record) // sekaligus absen masuk (diabaikan bila sudah ada)
		}
	default:
		written, err = absenData.insert(record)
	}
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Presensi gagal disimpan. Coba lagi"})
		return
	}
	if !written {
		reply(false, "Presensi sudah tercatat sebelumnya (tidak dicatat ulang).")
		return
	}
	reply(true, fmt.Sprintf("Presensi %s BERHASIL dicatat pukul %s WITA (tgl dinas %s).", absenNama[aksi], now.Format("15:04"), tglDinasLabel(opsi.Tanggal)))
}

// petaArea: area yang digambar di peta halaman absen.
type petaArea struct {
	Nama    string     `json:"nama"`
	Jenis   string     `json:"jenis"` // kantor | upacara | dinas
	Center  geoPoint   `json:"center"`
	Radius  int        `json:"radius"`
	Polygon []geoPoint `json:"polygon,omitempty"`
}

type lokasiHasil struct {
	Area     *petaArea
	Jarak    int // meter ke titik area (juga saat di luar)
	OK       bool
	Label    string
	Pesan    string
	Desa     string
	Distance int
	Nama     string // lokasi upacara terpilih
}

// evaluasiLokasi: SATU-SATUNYA pemeriksaan lokasi, dipakai tombol di layar (status) DAN saat absen
// dikirim — sehingga tombol aktif hanya bila jadwal & lokasi sama-sama sesuai, dan server tetap
// memeriksa ulang. Lepas jaga (pola tanpa cek lokasi) tidak memerlukan GPS.
func evaluasiLokasi(ctx absenCtx, o absenOpsi, req simulasiRequest) lokasiHasil {
	if req.MockLocation {
		return lokasiHasil{Label: "Lokasi", Pesan: "Lokasi palsu (aplikasi fake GPS) terdeteksi di perangkat"}
	}
	pos, gpsOK, gpsMessage := readAbsenLocation(req)
	accuracy := ""
	if gpsOK {
		accuracy = fmt.Sprintf(" · akurasi GPS ±%.0f m", req.Location.Accuracy)
	}
	officeOf := func(unit, label string) lokasiHasil {
		if !gpsOK {
			return lokasiHasil{Label: label, Pesan: gpsMessage}
		}
		g, err := geofencePegawai(simulasiData.geofence, ctx.nip, unit, pos) // + lokasi penugasan pegawai
		where := fmt.Sprintf("%.0f m dari titik %s", g.Distance, g.Name)
		switch {
		case err != nil:
			return lokasiHasil{Label: label, Pesan: "Titik presensi tidak dapat diperiksa"}
		case !g.Found:
			return lokasiHasil{Label: label, Pesan: "Titik presensi unit belum tersedia di APEL"}
		}
		jenis := map[bool]string{true: "kantor", false: "upacara"}[label == "Lokasi"]
		area := &petaArea{Nama: g.Name, Jenis: jenis, Center: g.Center, Radius: g.Radius, Polygon: g.Points}
		jarak := int(math.Round(g.Distance))
		if !g.Inside {
			return lokasiHasil{Label: label, Pesan: "Di luar area " + map[bool]string{true: "unit kerja", false: "upacara"}[label == "Lokasi"] + ": " + where + accuracy, Area: area, Jarak: jarak, Nama: g.Name}
		}
		return lokasiHasil{OK: true, Label: label, Pesan: "Di dalam area " + map[bool]string{true: "unit kerja", false: "upacara"}[label == "Lokasi"] + ": " + where + accuracy, Distance: jarak, Area: area, Jarak: jarak, Nama: g.Name}
	}
	office := func() lokasiHasil { return officeOf(ctx.unit, "Lokasi") }
	selayar := func(label string) lokasiHasil {
		if !gpsOK {
			return lokasiHasil{Label: label, Pesan: gpsMessage}
		}
		desa, inside, err := wfaDesaAt(pos)
		switch {
		case err != nil:
			return lokasiHasil{Label: label, Pesan: "Batas wilayah Kab. Selayar belum tersedia di server"}
		case !inside:
			return lokasiHasil{Label: label, Pesan: "Di luar wilayah Kab. Kepulauan Selayar" + accuracy}
		}
		name := strings.TrimSpace(desa.Nama + ", Kec. " + desa.Kecamatan)
		return lokasiHasil{OK: true, Label: label, Pesan: "Desa/Kel. " + name + accuracy, Desa: name}
	}
	area := func(label, name string, center geoPoint, radius int, polygon []geoPoint) lokasiHasil {
		jenis := map[bool]string{true: "upacara", false: "dinas"}[label == "Lokasi upacara"]
		peta := &petaArea{Nama: name, Jenis: jenis, Center: center, Radius: radius, Polygon: polygon}
		if !gpsOK {
			return lokasiHasil{Label: label, Pesan: gpsMessage, Area: peta}
		}
		d := distanceMeters(pos, center)
		jarak := int(math.Round(d))
		if (len(polygon) >= 3 && insidePolygon(pos, polygon)) || (len(polygon) < 3 && radius > 0 && d <= float64(radius)) {
			return lokasiHasil{OK: true, Label: label, Pesan: fmt.Sprintf("Di lokasi %s (%.0f m)%s", name, d, accuracy), Distance: jarak, Nama: name, Area: peta, Jarak: jarak}
		}
		return lokasiHasil{Label: label, Pesan: fmt.Sprintf("Di luar lokasi: %.0f m dari %s%s", d, name, accuracy), Nama: name, Area: peta, Jarak: jarak}
	}
	switch o.Aksi {
	case "masuk":
		if !o.pola.CekLokasi {
			return lokasiHasil{OK: true, Label: "Lokasi", Pesan: "Tidak diperiksa (" + o.pola.Nama + ": di mana saja)"}
		}
		return office()
	case "pulang":
		if o.row != nil && o.row.Jenis == "wfa" {
			return selayar("Lokasi WFA")
		}
		if !o.pola.CekLokasi {
			return lokasiHasil{OK: true, Label: "Lokasi", Pesan: "Tidak diperiksa"}
		}
		return office()
	case "wfa":
		return selayar("Lokasi WFA")
	case "upacara":
		if o.upacara == nil {
			return lokasiHasil{Label: "Lokasi upacara", Pesan: "Jadwal upacara tidak ditemukan"}
		}
		switch {
		case o.upacara.Khusus: // peserta dipilih per jadwal: lokasi pusat jadwal itu
		case ctx.upacara.Aturan == "SENDIRI": // di kantor/unit kerja masing-masing (mis. sekolah)
			return officeOf(ctx.unit, "Lokasi upacara")
		case ctx.upacara.Aturan == "UNIT_INI": // di kantor unit yang diatur (mis. kecamatan; kelurahan ikut)
			return officeOf(ctx.upacara.Unit, "Lokasi upacara")
		}
		places, err := absenData.upacaraLokasi(o.upacara.LokasiIDs)
		if err != nil || len(places) == 0 {
			return lokasiHasil{Label: "Lokasi upacara", Pesan: "Lokasi upacara belum diatur"}
		}
		best := places[0]
		if gpsOK {
			for _, p := range places {
				if (len(p.Polygon) >= 3 && insidePolygon(pos, p.Polygon)) || (len(p.Polygon) < 3 && p.Radius > 0 && distanceMeters(pos, p.Center) <= float64(p.Radius)) {
					best = p
					break
				}
				if distanceMeters(pos, p.Center) < distanceMeters(pos, best.Center) {
					best = p
				}
			}
		}
		return area("Lokasi upacara", best.Nama, best.Center, best.Radius, best.Polygon)
	case "dinasluar":
		return lokasiDinasLuar(ctx.dinas, pos, gpsOK, gpsMessage, accuracy)
	case "dinas":
		l, err := absenData.dinasLokasi(ctx.lain.RefUUID)
		switch {
		case err != nil:
			return lokasiHasil{Label: "Lokasi dinas", Pesan: "Data surat tugas tidak dapat diperiksa"}
		case l.TujuanKec != "": // tujuan kecamatan (+desa) Kab. Selayar
			if !gpsOK {
				return lokasiHasil{Label: "Lokasi dinas", Pesan: gpsMessage}
			}
			desa, inside, err := wfaDesaAt(pos)
			tujuan := "Kec. " + l.TujuanKec
			if l.TujuanDesa != "" {
				tujuan = "Desa/Kel. " + l.TujuanDesa + ", " + tujuan
			}
			switch {
			case err != nil:
				return lokasiHasil{Label: "Lokasi dinas", Pesan: "Batas wilayah Kab. Selayar belum tersedia di server"}
			case !inside:
				return lokasiHasil{Label: "Lokasi dinas", Pesan: "Di luar wilayah Kab. Selayar (tujuan: " + tujuan + ")" + accuracy}
			case !strings.EqualFold(desa.Kecamatan, l.TujuanKec) || (l.TujuanDesa != "" && !strings.EqualFold(desa.Nama, l.TujuanDesa)):
				return lokasiHasil{Label: "Lokasi dinas", Pesan: "Posisi Anda di Desa/Kel. " + desa.Nama + ", Kec. " + desa.Kecamatan + " (tujuan: " + tujuan + ")" + accuracy}
			}
			name := strings.TrimSpace(desa.Nama + ", Kec. " + desa.Kecamatan)
			return lokasiHasil{OK: true, Label: "Lokasi dinas", Pesan: "Di lokasi tujuan: Desa/Kel. " + name + accuracy, Desa: name}
		case l.Set:
			return area("Lokasi dinas", firstNonEmpty(l.Tempat, "lokasi kegiatan"), l.Center, l.Radius, l.Polygon)
		}
		return selayar("Lokasi dinas") // lokasi belum diisi admin: cukup di wilayah Selayar
	}
	return lokasiHasil{Label: "Lokasi", Pesan: "Jenis absen tidak dikenal"}
}

// lokasiDinasLuar: GPS harus berada di kab/kota tujuan (bila diisi); bila tujuan kosong cukup di luar Kab. Selayar.
// Bila berkas batas kab/kota belum ada di server: cukup di luar wilayah Kab. Selayar.
func lokasiDinasLuar(l dinasLokasi, pos geoPoint, gpsOK bool, gpsMessage, accuracy string) lokasiHasil {
	label := "Lokasi dinas luar"
	if !gpsOK {
		return lokasiHasil{Label: label, Pesan: gpsMessage}
	}
	kode, nama, ok, err := kabkotaAt(pos)
	if err != nil {
		if _, diSelayar, e := wfaDesaAt(pos); e == nil && diSelayar {
			return lokasiHasil{Label: label, Pesan: "Masih di wilayah Kab. Kepulauan Selayar" + accuracy}
		}
		return lokasiHasil{OK: true, Label: label, Pesan: "Di luar Kab. Kepulauan Selayar (batas kab/kota belum tersedia di server)" + accuracy, Desa: "Luar Kab. Selayar"}
	}
	tujuan := kabkotaNama(l.TujuanKabKota)
	switch {
	case !ok:
		return lokasiHasil{Label: label, Pesan: "Posisi tidak berada di wilayah kab/kota mana pun (mis. di laut)" + accuracy}
	case l.TujuanKabKota == "" && kode == kodeSelayar:
		return lokasiHasil{Label: label, Pesan: "Masih di wilayah Kab. Kepulauan Selayar" + accuracy}
	case l.TujuanKabKota != "" && kode != l.TujuanKabKota:
		return lokasiHasil{Label: label, Pesan: "Posisi Anda di " + nama + " (tujuan: " + firstNonEmpty(tujuan, l.TujuanKabKota) + ")" + accuracy}
	}
	return lokasiHasil{OK: true, Label: label, Pesan: "Di " + nama + accuracy, Desa: nama}
}

func fwaHariIni(ctx absenCtx) bool {
	for _, r := range ctx.rows {
		if r.Day == ctx.day && r.Jenis == "wfa" {
			return true
		}
	}
	return false
}

func readAbsenLocation(req simulasiRequest) (geoPoint, bool, string) {
	switch {
	case req.Location == nil:
		reason := strings.TrimSpace(req.LocationError)
		if reason == "" || len(reason) > 160 {
			reason = "Lokasi tidak tersedia"
		}
		return geoPoint{}, false, reason
	case math.IsNaN(req.Location.Latitude) || math.IsNaN(req.Location.Longitude) || math.Abs(req.Location.Latitude) > 90 ||
		math.Abs(req.Location.Longitude) > 180 || req.Location.Accuracy <= 0 || math.IsInf(req.Location.Accuracy, 0):
		return geoPoint{}, false, "Data lokasi GPS tidak valid"
	}
	return geoPoint{Lat: req.Location.Latitude, Lng: req.Location.Longitude}, true, ""
}

// absenStatus: pilihan absen (aktif / alasan tidak aktif) + catatan hari ini untuk tampilan.
func absenStatus(c *gin.Context, nip, pnsID, unit string, now time.Time, lokasi simulasiRequest) {
	ctx, err := loadAbsenCtx(nip, pnsID, unit, now)
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Data presensi tidak dapat diperiksa: " + err.Error()})
		return
	}
	opsi := append(append(ctx.khususOpsi(), ctx.masukOpsi()...), ctx.pulangOpsi()...)
	areas := []*petaArea{}
	seenArea := map[string]bool{}
	tambahArea := func(a *petaArea) {
		key := fmt.Sprintf("%s|%.6f|%.6f", a.Jenis, a.Center.Lat, a.Center.Lng)
		if a.Center.Lat == 0 && a.Center.Lng == 0 || seenArea[key] {
			return
		}
		seenArea[key] = true
		areas = append(areas, a)
	}
	// Tombol aktif hanya bila jadwal DAN lokasi sesuai; alasan lokasi ditampilkan bila belum.
	for i := range opsi {
		opsi[i].JadwalAktif = opsi[i].Aktif
		if !opsi[i].Aktif {
			continue
		}
		h := evaluasiLokasi(ctx, opsi[i], lokasi)
		if h.OK {
			opsi[i].LokasiInfo = h.Pesan
		} else {
			opsi[i].Aktif, opsi[i].Alasan = false, h.Pesan
		}
		if h.Area != nil {
			jarak := h.Jarak
			opsi[i].Jarak = &jarak
			tambahArea(h.Area)
		}
	}
	pos, gpsOK, gpsMessage := readAbsenLocation(lokasi)
	// Area kantor selalu ditampilkan di peta (meski belum ada tombol yang sesuai jadwal).
	if g, err := simulasiData.geofence(ctx.unit, pos); err == nil && g.Found {
		tambahArea(&petaArea{Nama: g.Name, Jenis: "kantor", Center: g.Center, Radius: g.Radius, Polygon: g.Points})
	}
	lokasiSah := []string{}
	if g, err := simulasiData.geofence(ctx.unit, pos); err == nil && g.Found {
		lokasiSah = append(lokasiSah, g.Name)
	}
	for _, t := range muatTitikPenugasan(nip, now.Format("2006-01-02")) { // lokasi penugasan pegawai
		tambahArea(&petaArea{Nama: "Penugasan " + t.Nama, Jenis: "kantor", Center: geoPoint{t.Lat, t.Lng}, Radius: t.Radius, Polygon: t.Polygon})
		lokasiSah = append(lokasiSah, t.Nama+" (lokasi tambahan)")
	}
	// Jadwal shift hari ini (APEL): tandai tombol masuk shift sesuai/beda jadwal; yang sesuai di atas.
	var jadwalShift gin.H
	var kodeJadwal string
	if db.DBa != nil && db.DBa.QueryRow(`SELECT pola FROM jadwal_shift WHERE nip=? AND tanggal=?`, nip, ctx.day).Scan(&kodeJadwal) == nil && kodeJadwal != "" {
		jadwalShift = gin.H{"kode": kodeJadwal, "nama": "Libur", "jam": ""}
		for _, p := range polaShift() {
			if p["kode"] == kodeJadwal {
				jadwalShift = gin.H{"kode": kodeJadwal, "nama": p["nama"], "jam": p["jam"]}
			}
		}
		for i := range opsi {
			if opsi[i].Aksi != "masuk" || opsi[i].pola.Kelompok != "shift" {
				continue
			}
			switch {
			case opsi[i].Pola == kodeJadwal:
				opsi[i].Jadwal = "sesuai"
			case opsi[i].Pola == "LEPAS" || opsi[i].Pola == "ONCALL":
				// lepas jaga (setelah malam) & oncall tidak dibandingkan dengan jadwal
			default:
				opsi[i].Jadwal = "beda"
			}
		}
		sort.SliceStable(opsi, func(a, b int) bool { return opsi[a].Jadwal == "sesuai" && opsi[b].Jadwal != "sesuai" })
	}
	mulaiBerlaku := presensiMulai()
	peta := gin.H{"area": areas}
	if gpsOK && !lokasi.MockLocation {
		peta["posisi"] = gin.H{"lat": pos.Lat, "lng": pos.Lng, "akurasi_m": lokasi.Location.Accuracy}
	}
	rows := make([]gin.H, 0, len(ctx.rows))
	for _, r := range ctx.rows {
		if r.Day != ctx.day && !(r.Out.IsZero() || r.Out.Format("2006-01-02") == ctx.day) {
			continue // kemarin & sudah selesai sebelum hari ini
		}
		masuk := tglJam(r.In, now)
		if r.TanpaMasuk {
			masuk = "—"
		}
		pulang := ""
		if !r.Out.IsZero() {
			pulang = tglJam(r.Out, now)
		}
		nama := r.Pola
		if p, ok := ctx.all[r.Pola]; ok {
			nama = p.Nama
		}
		rows = append(rows, gin.H{"tanggal_dinas": r.Day, "pola": r.Pola, "pola_nama": nama, "jenis": r.Jenis, "masuk": masuk, "pulang": pulang, "tanpa_masuk": r.TanpaMasuk})
	}
	lain := gin.H(nil)
	if ctx.adaLain {
		lain = gin.H{"jenis": ctx.lain.Jenis, "keterangan": ctx.lain.Keterangan, "pesan": ctx.lainAlasan()}
	}
	polas := make([]string, 0, len(ctx.polas))
	for _, p := range ctx.polas {
		polas = append(polas, p.Kode)
	}
	c.Header("Cache-Control", "no-store")
	c.JSON(http.StatusOK, gin.H{"tanggal": ctx.day, "hari": ctx.hari, "jam_server": now.Format("15:04"), "libur": ctx.libur, "libur_nama": ctx.liburNama,
		"pola_unit": polas, "absen_lain": lain, "opsi": opsi, "catatan": rows, "metode": ctx.metode, "peta": peta, "fwa": ctx.fwa, "fwa_hari_ini": fwaHariIni(ctx), "laporan_jumlah": absenData.laporanCount(nip, ctx.day),
		"lokasi_terbaca": gpsOK && !lokasi.MockLocation, "lokasi_pesan": map[bool]string{true: "", false: gpsMessage}[gpsOK],
		"jadwal_shift": jadwalShift, "lokasi_sah": lokasiSah, "belum_berlaku": mulaiBerlaku != "" && ctx.day < mulaiBerlaku, "presensi_mulai": mulaiBerlaku})
}

// ---------------------------------------------------------------------------
// Jadwal upacara (super_admin): lihat, tambah, batalkan.

var tanggalPattern = func(s string) bool { _, err := time.Parse("2006-01-02", s); return err == nil }
var jamPattern = func(s string) bool { _, err := time.Parse("15:04", s); return err == nil }

func upacaraAdminGuard(c *gin.Context) (string, bool) {
	_, _, _, level, ok := portalAccount(c)
	if !ok {
		return "", false
	}
	username := ssoUsername(c)
	if level != 1 {
		c.JSON(http.StatusForbidden, gin.H{"error": "Hanya super admin"})
		return "", false
	}
	return username, true
}

func upacaraList(c *gin.Context) {
	if _, ok := upacaraAdminGuard(c); !ok {
		return
	}
	from := simulasiData.now().AddDate(0, 0, -7).Format("2006-01-02")
	rows, err := db.DBa.Query(`SELECT j.id,j.nama,DATE_FORMAT(j.tanggal,'%Y-%m-%d'),TIME_FORMAT(j.jam_buka,'%H:%i'),TIME_FORMAT(j.jam_tutup,'%H:%i'),
		COALESCE(j.lokasi_ids,''),j.dibatalkan,COALESCE(j.dibuat_oleh,''),(SELECT COUNT(*) FROM upacara_hadir h WHERE h.jadwal_id=j.id)
		FROM upacara_jadwal j WHERE j.tanggal>=? ORDER BY j.tanggal,j.jam_buka LIMIT 200`, from)
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Jadwal upacara tidak dapat dibaca"})
		return
	}
	defer rows.Close()
	list := make([]gin.H, 0)
	for rows.Next() {
		var id, nama, tanggal, buka, tutup, ids, oleh string
		var batal, hadir int
		if rows.Scan(&id, &nama, &tanggal, &buka, &tutup, &ids, &batal, &oleh, &hadir) == nil {
			var lokasi []string
			_ = json.Unmarshal([]byte(ids), &lokasi)
			list = append(list, gin.H{"id": id, "nama": nama, "tanggal": tanggal, "buka": buka, "tutup": tutup, "lokasi_ids": lokasi, "dibatalkan": batal == 1, "dibuat_oleh": oleh, "hadir": hadir})
		}
	}
	places, _ := absenData.upacaraLokasi(nil)
	lokasi := make([]gin.H, 0, len(places))
	for _, p := range places {
		lokasi = append(lokasi, gin.H{"id": p.ID, "nama": p.Nama})
	}
	c.JSON(http.StatusOK, gin.H{"jadwal": list, "lokasi": lokasi})
}

func upacaraCreate(c *gin.Context) {
	username, ok := upacaraAdminGuard(c)
	if !ok {
		return
	}
	var req struct {
		Tanggal   string   `json:"tanggal"`
		Nama      string   `json:"nama"`
		Buka      string   `json:"jam_buka"`
		Tutup     string   `json:"jam_tutup"`
		LokasiIDs []string `json:"lokasi_ids"`
	}
	if c.ShouldBindJSON(&req) != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Data tidak valid"})
		return
	}
	req.Nama = strings.TrimSpace(req.Nama)
	switch {
	case !tanggalPattern(req.Tanggal):
		c.JSON(http.StatusBadRequest, gin.H{"error": "Tanggal tidak valid"})
		return
	case req.Nama == "" || len(req.Nama) > 150:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Nama upacara wajib diisi (maks 150 karakter)"})
		return
	case !jamPattern(req.Buka) || !jamPattern(req.Tutup) || req.Buka >= req.Tutup:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Jam buka harus lebih awal dari jam tutup"})
		return
	case len(req.LokasiIDs) > 50:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Terlalu banyak lokasi"})
		return
	}
	if req.Tanggal < simulasiData.now().Format("2006-01-02") {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Tanggal sudah lewat"})
		return
	}
	known := map[string]bool{}
	places, _ := absenData.upacaraLokasi(nil)
	for _, p := range places {
		known[p.ID] = true
	}
	for _, id := range req.LokasiIDs {
		if !known[id] {
			c.JSON(http.StatusBadRequest, gin.H{"error": "Lokasi upacara tidak dikenal"})
			return
		}
	}
	ids, _ := json.Marshal(req.LokasiIDs)
	id := newUUID()
	_, err := db.DBa.Exec(`INSERT INTO upacara_jadwal (id,tanggal,nama,jam_buka,jam_tutup,lokasi_ids,dibatalkan,dibuat_oleh,dibuat_pada) VALUES (?,?,?,?,?,?,0,?,NOW())`,
		id, req.Tanggal, req.Nama, req.Buka+":00", req.Tutup+":00", string(ids), username)
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Jadwal upacara gagal disimpan"})
		return
	}
	c.JSON(http.StatusOK, gin.H{"success": true, "id": id})
}

func upacaraCancel(c *gin.Context) {
	if _, ok := upacaraAdminGuard(c); !ok {
		return
	}
	result, err := db.DBa.Exec(`UPDATE upacara_jadwal SET dibatalkan=1 WHERE id=? AND dibatalkan=0`, c.Param("id"))
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Jadwal tidak dapat dibatalkan"})
		return
	}
	if n, _ := result.RowsAffected(); n != 1 {
		c.JSON(http.StatusNotFound, gin.H{"error": "Jadwal tidak ditemukan atau sudah dibatalkan"})
		return
	}
	c.JSON(http.StatusOK, gin.H{"success": true})
}

func newUUID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}
