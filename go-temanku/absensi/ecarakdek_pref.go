package absensi

// PREFERENSI PRESENSI e-CARAKDEK - /ecarakdek/api/v1/absen/...
// Dipasang lewat RegisterEcarakdekWebAbsen (grup sudah DualAuth + EcarakdekScope + ecarakdekAkun).
//
//   Titik koordinat   : super admin dinas menambah (sekolah yang belum punya titik) & mengubah titik
//                       sekolah, radius ATAU poligon - sama dengan menu Lokasi APEL.
//   Hari libur        : libur APEL (nasional/cuti bersama) hanya dibaca;
//                       Libur Semester (tabel APEL libur_sekolah, berlaku khusus guru/tendik) diisi super admin.
//   Jadwal absensi    : di Jadwal Absensi APEL (shift_master/shift_detail) dengan kategori terkunci TK/SD/SMP.
//                       Guru/tendik memakai jadwal sesuai jenjang sekolahnya (dari nama unit) bila ada;
//                       selain itu jadwal reguler APEL. OPD lain tidak terpengaruh.
//   Absensi titipan   : lokasi absen tambahan per pegawai (lokasi_penugasan APEL). Dari e-Carakdek hanya
//                       PENGAJUAN tambah/ubah/hapus (lokasi_penugasan_usulan) yang disetujui super admin APEL.

import (
	"database/sql"
	"encoding/json"
	"math"
	"net/http"
	"path"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"temanku/asn"
	"temanku/db"
	"temanku/repo"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"
)

func ecarakdekOleh(s asn.EcarakdekScope) string { return s.Username + " (e-Carakdek)" }

/* ======================================================
   TITIK KOORDINAT (geolokasi jenis presensi)
====================================================== */

// ecarakdekPolygon: "" (mode radius) atau JSON [{lat,lng},...] minimal 3 titik yang valid.
func ecarakdekPolygon(raw string) (interface{}, bool) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return nil, true
	}
	var titik []struct {
		Lat float64 `json:"lat"`
		Lng float64 `json:"lng"`
	}
	if len(raw) > 20000 || json.Unmarshal([]byte(raw), &titik) != nil || len(titik) < 3 || len(titik) > 200 {
		return nil, false
	}
	for _, t := range titik {
		if math.Abs(t.Lat) > 90 || math.Abs(t.Lng) > 180 || (t.Lat == 0 && t.Lng == 0) {
			return nil, false
		}
	}
	b, _ := json.Marshal(titik)
	return string(b), true
}

type ecarakdekTitikReq struct {
	Sumber  string `json:"sumber"` // unor (unit resmi SIASN) | manual (instansi lain / sekolah swasta non-SIASN)
	Unit    string `json:"unit_id"`
	Manual  string `json:"nama_instansi_manual"`
	Nama    string `json:"nama"`
	Lat     string `json:"lat"`
	Lng     string `json:"lng"`
	Radius  int    `json:"radius"`
	Polygon string `json:"polygon"`
}

// validasi bersama tambah/ubah; mengembalikan nilai polygon untuk kolom DB.
func (r *ecarakdekTitikReq) valid(c *gin.Context) (interface{}, bool) {
	r.Nama, r.Lat, r.Lng, r.Manual = strings.TrimSpace(r.Nama), strings.TrimSpace(r.Lat), strings.TrimSpace(r.Lng), strings.TrimSpace(r.Manual)
	poly, polyOK := ecarakdekPolygon(r.Polygon)
	switch {
	case r.Nama == "" || len(r.Nama) > 150:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Nama lokasi wajib diisi (maks 150 karakter)"})
	case !koordinatValid(r.Lat, r.Lng):
		c.JSON(http.StatusBadRequest, gin.H{"error": "Latitude/longitude tidak valid"})
	case !polyOK:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Polygon minimal harus punya 3 titik koordinat yang valid"})
	case poly == nil && (r.Radius < 10 || r.Radius > 500):
		c.JSON(http.StatusBadRequest, gin.H{"error": "Radius harus 10–500 meter"})
	default:
		if poly != nil && r.Radius <= 0 {
			r.Radius = 100 // kolom radius tetap terisi untuk mode poligon
		}
		return poly, true
	}
	return nil, false
}

// ecarakdekLokasiManual: titik instansi lain / sekolah swasta (non-SIASN), dikelompokkan per nama instansi.
func ecarakdekLokasiManual() []gin.H {
	out := []gin.H{}
	rows, err := db.DBa.Query(`SELECT id, TRIM(nama), TRIM(nama_instansi_manual), lat, lng, radius, COALESCE(polygon,'') FROM geolokasi
		WHERE jenis = 'presensi' AND COALESCE(id_unit,'') = '' AND COALESCE(nama_instansi_manual,'') <> '' ORDER BY nama_instansi_manual, nama`)
	if err != nil {
		return out
	}
	defer rows.Close()
	idx := map[string]int{}
	for rows.Next() {
		var id, nama, inst, lat, lng, poly string
		var radius int
		if rows.Scan(&id, &nama, &inst, &lat, &lng, &radius, &poly) != nil {
			continue
		}
		t := gin.H{"id": id, "nama": nama, "lat": strings.TrimSpace(lat), "lng": strings.TrimSpace(lng), "radius": radius, "polygon": poly}
		if i, ok := idx[inst]; ok {
			out[i]["titik"] = append(out[i]["titik"].([]gin.H), t)
			continue
		}
		idx[inst] = len(out)
		out = append(out, gin.H{"unit_id": "", "unit_nama": inst, "manual": true, "metode": "LOKASI", "titik": []gin.H{t}})
	}
	return out
}

// POST /absen/lokasi (super admin dinas) - tambah titik (form sama dengan Lokasi APEL):
//   sumber unor   : sekolah dalam cakupan yang belum punya titik (aturan APEL: satu titik presensi per unit)
//   sumber manual : instansi lain / sekolah swasta non-SIASN (nama instansi diketik)
func ecarakdekLokasiTambah(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	var r ecarakdekTitikReq
	if c.ShouldBindJSON(&r) != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Data tidak valid"})
		return
	}
	manual := r.Sumber == "manual"
	if !manual && !asn.EcarakdekUnitDalamScope(s, r.Unit) {
		c.JSON(http.StatusForbidden, gin.H{"error": "Pilih sekolah dalam cakupan akun"})
		return
	}
	poly, ok := r.valid(c)
	if !ok {
		return
	}
	var unit, inst interface{} = r.Unit, nil
	if manual {
		if r.Manual == "" || len(r.Manual) > 150 {
			c.JSON(http.StatusBadRequest, gin.H{"error": "Nama instansi wajib diisi (maks 150 karakter)"})
			return
		}
		unit, inst = "", r.Manual
	} else {
		var ada int
		_ = db.DBa.QueryRow(`SELECT COUNT(*) FROM geolokasi WHERE id_unit = ? AND jenis = 'presensi'`, r.Unit).Scan(&ada)
		if ada > 0 {
			c.JSON(http.StatusConflict, gin.H{"error": "Sekolah ini sudah punya titik absen; ubah titiknya."})
			return
		}
	}
	id := uuid.New().String()
	if _, err := db.DBa.Exec(`INSERT INTO geolokasi (id, nama, jenis, id_unit, nama_instansi_manual, lat, lng, radius, polygon)
		VALUES (?,?,'presensi',?,?,?,?,?,?)`, id, r.Nama, unit, inst, r.Lat, r.Lng, r.Radius, poly); err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Titik lokasi gagal disimpan"})
		return
	}
	c.JSON(http.StatusCreated, gin.H{"success": true, "id": id, "message": "Titik absen ditambahkan"})
}

// PUT /absen/lokasi/:id (super admin dinas) - ubah titik: radius atau poligon (seperti APEL).
// Titik sekolah: unit pemiliknya tidak dapat dipindah. Titik instansi manual: nama instansi boleh diubah.
func ecarakdekLokasiUbah(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	id := strings.TrimSpace(c.Param("id"))
	var unit, inst string
	if db.DBa.QueryRow(`SELECT COALESCE(id_unit,''), COALESCE(nama_instansi_manual,'') FROM geolokasi WHERE id = ? AND jenis = 'presensi'`, id).Scan(&unit, &inst) != nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "Titik lokasi tidak ditemukan"})
		return
	}
	manual := unit == "" && inst != ""
	if !manual && !asn.EcarakdekUnitDalamScope(s, unit) {
		c.JSON(http.StatusForbidden, gin.H{"error": "Titik ini bukan milik sekolah dalam cakupan akun"})
		return
	}
	var r ecarakdekTitikReq
	if c.ShouldBindJSON(&r) != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Data tidak valid"})
		return
	}
	poly, ok := r.valid(c)
	if !ok {
		return
	}
	if manual && r.Manual != "" && len(r.Manual) <= 150 {
		inst = r.Manual
	}
	var instVal interface{}
	if manual {
		instVal = inst
	}
	if _, err := db.DBa.Exec(`UPDATE geolokasi SET nama = ?, lat = ?, lng = ?, radius = ?, polygon = ?, nama_instansi_manual = COALESCE(?, nama_instansi_manual) WHERE id = ?`,
		r.Nama, r.Lat, r.Lng, r.Radius, poly, instVal, id); err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Titik lokasi gagal disimpan"})
		return
	}
	c.JSON(http.StatusOK, gin.H{"success": true, "message": "Titik absen diperbarui"})
}

// DELETE /absen/lokasi/:id (super admin dinas) - hapus titik GANDA: sekolah harus tetap punya titik lain
// (titik terakhir sekolah tidak dapat dihapus dari e-Carakdek) dan titik tidak sedang dipakai absensi titipan.
func ecarakdekLokasiHapus(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	id := strings.TrimSpace(c.Param("id"))
	var unit, inst string
	if db.DBa.QueryRow(`SELECT COALESCE(id_unit,''), COALESCE(nama_instansi_manual,'') FROM geolokasi WHERE id = ? AND jenis = 'presensi'`, id).Scan(&unit, &inst) != nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "Titik lokasi tidak ditemukan"})
		return
	}
	manual := unit == "" && inst != ""
	if !manual && !asn.EcarakdekUnitDalamScope(s, unit) {
		c.JSON(http.StatusForbidden, gin.H{"error": "Titik ini bukan milik sekolah dalam cakupan akun"})
		return
	}
	var lain, dipakai int
	if manual {
		_ = db.DBa.QueryRow(`SELECT COUNT(*) FROM geolokasi WHERE jenis = 'presensi' AND COALESCE(id_unit,'') = '' AND nama_instansi_manual = ? AND id <> ?`, inst, id).Scan(&lain)
	} else {
		_ = db.DBa.QueryRow(`SELECT COUNT(*) FROM geolokasi WHERE jenis = 'presensi' AND id_unit = ? AND id <> ?`, unit, id).Scan(&lain)
	}
	if lain == 0 {
		c.JSON(http.StatusConflict, gin.H{"error": "Ini satu-satunya titik absen; ubah titiknya, jangan dihapus"})
		return
	}
	_ = db.DBa.QueryRow(`SELECT COUNT(*) FROM lokasi_penugasan WHERE geolokasi_id = ?`, id).Scan(&dipakai)
	if dipakai > 0 {
		c.JSON(http.StatusConflict, gin.H{"error": "Titik ini dipakai absensi titipan; hapus titipannya dulu"})
		return
	}
	if _, err := db.DBa.Exec(`DELETE FROM geolokasi WHERE id = ? AND jenis = 'presensi'`, id); err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menghapus titik"})
		return
	}
	c.JSON(http.StatusOK, gin.H{"success": true, "message": "Titik ganda dihapus"})
}

/* ======================================================
   HARI LIBUR (APEL, baca) & LIBUR SEMESTER (libur_sekolah)
====================================================== */

func tahunQuery(c *gin.Context) string {
	t := strings.TrimSpace(c.Query("tahun"))
	if n, err := strconv.Atoi(t); err == nil && n >= 2000 && n <= 2100 {
		return t
	}
	return time.Now().In(witaZone).Format("2006")
}

// GET /absen/libur?tahun= - libur APEL (hanya lihat) + libur semester sekolah.
func ecarakdekLiburList(c *gin.Context) {
	tahun := tahunQuery(c)
	apel := []gin.H{}
	if rows, err := db.DBa.Query(`SELECT id, nama, DATE_FORMAT(tanggal,'%Y-%m-%d') FROM libur WHERE YEAR(tanggal) = ? ORDER BY tanggal`, tahun); err == nil {
		for rows.Next() {
			var id, nama, tgl string
			if rows.Scan(&id, &nama, &tgl) == nil {
				apel = append(apel, gin.H{"id": id, "nama": nama, "tanggal": tgl})
			}
		}
		rows.Close()
	} else {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Hari libur APEL tidak dapat dibaca"})
		return
	}
	sekolah := []gin.H{}
	if rows, err := db.DBa.Query(`SELECT id, nama, DATE_FORMAT(mulai,'%Y-%m-%d'), DATE_FORMAT(selesai,'%Y-%m-%d'), COALESCE(dibuat_oleh,'')
		FROM libur_sekolah WHERE YEAR(mulai) = ? OR YEAR(selesai) = ? ORDER BY mulai`, tahun, tahun); err == nil {
		for rows.Next() {
			var id int64
			var nama, m, sl, oleh string
			if rows.Scan(&id, &nama, &m, &sl, &oleh) == nil {
				d1, _ := time.Parse("2006-01-02", m)
				d2, _ := time.Parse("2006-01-02", sl)
				sekolah = append(sekolah, gin.H{"id": id, "nama": nama, "mulai": m, "selesai": sl, "hari": int(d2.Sub(d1).Hours()/24) + 1, "oleh": oleh})
			}
		}
		rows.Close()
	}
	c.JSON(http.StatusOK, gin.H{"tahun": tahun, "libur": apel, "libur_sekolah": sekolah})
}

// POST /absen/libur-sekolah, PUT /absen/libur-sekolah/:id (super admin dinas)
func ecarakdekLiburSekolahSimpan(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	var r struct {
		Nama    string `json:"nama"`
		Mulai   string `json:"mulai"`
		Selesai string `json:"selesai"`
	}
	if c.ShouldBindJSON(&r) != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Data tidak valid"})
		return
	}
	r.Nama = strings.TrimSpace(r.Nama)
	d1, e1 := time.Parse("2006-01-02", r.Mulai)
	d2, e2 := time.Parse("2006-01-02", r.Selesai)
	if r.Nama == "" || len(r.Nama) > 150 || e1 != nil || e2 != nil || d2.Before(d1) || d2.Sub(d1) > 92*24*time.Hour {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Nama wajib; tanggal selesai tidak boleh sebelum mulai (maks. 3 bulan)"})
		return
	}
	id := strings.TrimSpace(c.Param("id"))
	if id != "" {
		var x int
		if db.DBa.QueryRow(`SELECT 1 FROM libur_sekolah WHERE id = ?`, id).Scan(&x) != nil {
			c.JSON(http.StatusNotFound, gin.H{"error": "Libur semester tidak ditemukan"})
			return
		}
	}
	var bentrok int
	_ = db.DBa.QueryRow(`SELECT COUNT(*) FROM libur_sekolah WHERE mulai <= ? AND selesai >= ? AND id <> ?`, r.Selesai, r.Mulai, firstNonEmpty(id, "0")).Scan(&bentrok)
	if bentrok > 0 {
		c.JSON(http.StatusConflict, gin.H{"error": "Rentang tanggal bertumpuk dengan libur semester yang sudah ada"})
		return
	}
	var err error
	if id == "" {
		_, err = db.DBa.Exec(`INSERT INTO libur_sekolah (nama, mulai, selesai, dibuat_oleh, dibuat_pada) VALUES (?,?,?,?,NOW())`, r.Nama, r.Mulai, r.Selesai, ecarakdekOleh(s))
	} else {
		_, err = db.DBa.Exec(`UPDATE libur_sekolah SET nama = ?, mulai = ?, selesai = ?, dibuat_oleh = ? WHERE id = ?`, r.Nama, r.Mulai, r.Selesai, ecarakdekOleh(s), id)
	}
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menyimpan"})
		return
	}
	c.JSON(http.StatusOK, gin.H{"success": true, "message": "Libur semester disimpan"})
}

// DELETE /absen/libur-sekolah/:id (super admin dinas)
func ecarakdekLiburSekolahHapus(c *gin.Context) {
	res, err := db.DBa.Exec(`DELETE FROM libur_sekolah WHERE id = ?`, c.Param("id"))
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menghapus"})
		return
	}
	if n, _ := res.RowsAffected(); n == 0 {
		c.JSON(http.StatusNotFound, gin.H{"error": "Libur semester tidak ditemukan"})
		return
	}
	c.JSON(http.StatusOK, gin.H{"success": true, "message": "Libur semester dihapus"})
}

/* ======================================================
   JADWAL ABSENSI SEKOLAH - di Jadwal Absensi APEL (shift_master/shift_detail)
   dengan kategori terkunci TK / SD / SMP (sesuai jenjang sekolah).
====================================================== */

// kategoriSekolah: kategori jadwal APEL yang boleh dikelola dari e-Carakdek.
var kategoriSekolah = []string{"TK", "SD", "SMP"}

func kategoriSekolahValid(k string) bool {
	for _, x := range kategoriSekolah {
		if x == k {
			return true
		}
	}
	return false
}

// pola jenjang dari nama unit (tbl_unit.nmunit); tidak ada kolom jenjang di SIMPEG.
// Nama boleh diawali "UPT"/"UPTD" (mis. "UPT SMPN BENTENG NO. 1 KEPULAUAN SELAYAR").
var (
	jenjangTK  = regexp.MustCompile(`(?i)^\s*(UPTD?\.?\s+)?(TK|TKN|TK\s*NEGERI|PAUD|KB|RA)\b`)
	jenjangSD  = regexp.MustCompile(`(?i)^\s*(UPTD?\.?\s+)?(SD|SDN|SD\s*NEGERI|SD\s*INPRES|SDI|MI|MIN)\b`)
	jenjangSMP = regexp.MustCompile(`(?i)^\s*(UPTD?\.?\s+)?(SMP|SMPN|SMP\s*NEGERI|SMP\s*SATAP|MTS|MTSN)\b`)
)

var (
	jenjangMu    sync.Mutex
	jenjangCache = map[string]struct {
		v   string
		exp time.Time
	}{}
)

// jenjangUnit: "TK" | "SD" | "SMP" | "" untuk unit (atau unit induk terdekat yang bernama sekolah).
func jenjangUnit(unit string) string {
	if unit == "" || db.DB == nil {
		return ""
	}
	now := time.Now()
	jenjangMu.Lock()
	hit, ok := jenjangCache[unit]
	jenjangMu.Unlock()
	if ok && now.Before(hit.exp) {
		return hit.v
	}
	v := ""
	for _, u := range absenData.unitChain(unit) {
		var nama string
		if db.DB.QueryRow(`SELECT COALESCE(nmunit,'') FROM tbl_unit WHERE id_unit = ?`, u).Scan(&nama) != nil {
			continue
		}
		switch {
		case jenjangTK.MatchString(nama):
			v = "TK"
		case jenjangSD.MatchString(nama):
			v = "SD"
		case jenjangSMP.MatchString(nama):
			v = "SMP"
		}
		if v != "" {
			break
		}
	}
	jenjangMu.Lock()
	if len(jenjangCache) > 5000 {
		jenjangCache = map[string]struct {
			v   string
			exp time.Time
		}{}
	}
	jenjangCache[unit] = struct {
		v   string
		exp time.Time
	}{v, now.Add(30 * time.Minute)}
	jenjangMu.Unlock()
	return v
}

type jadwalKategoriBaris struct{ BMasuk, TMasuk, Masuk, BPulang, TPulang string }

var (
	jadwalSekolahMu  sync.Mutex
	jadwalSekolahExp time.Time
	jadwalSekolahMap map[string]jadwalKategoriBaris // "kategori|hk|hari"
)

func jadwalSekolahReset() {
	jadwalSekolahMu.Lock()
	jadwalSekolahExp = time.Time{}
	jadwalSekolahMu.Unlock()
}

// jadwalSekolahSemua: shift_detail berkategori TK/SD/SMP (cache 60 detik).
func jadwalSekolahSemua() map[string]jadwalKategoriBaris {
	jadwalSekolahMu.Lock()
	defer jadwalSekolahMu.Unlock()
	if jadwalSekolahMap != nil && time.Now().Before(jadwalSekolahExp) {
		return jadwalSekolahMap
	}
	m := map[string]jadwalKategoriBaris{}
	if db.DBa != nil {
		rows, err := db.DBa.Query(`SELECT m.kategori, m.hari_kerja, d.hari, TIME_FORMAT(d.bmasuk,'%H:%i:%s'), TIME_FORMAT(d.tmasuk,'%H:%i:%s'),
			TIME_FORMAT(d.masuk,'%H:%i:%s'), TIME_FORMAT(d.bpulang,'%H:%i:%s'), TIME_FORMAT(d.tpulang,'%H:%i:%s')
			FROM shift_detail d JOIN shift_master m ON m.id = d.shift_id WHERE m.kategori IN ('TK','SD','SMP')`)
		if err == nil {
			for rows.Next() {
				var k, hari string
				var hk int
				var j jadwalKategoriBaris
				if rows.Scan(&k, &hk, &hari, &j.BMasuk, &j.TMasuk, &j.Masuk, &j.BPulang, &j.TPulang) == nil {
					m[k+"|"+strconv.Itoa(hk)+"|"+hari] = j
				}
			}
			rows.Close()
		}
	}
	jadwalSekolahMap, jadwalSekolahExp = m, time.Now().Add(60*time.Second)
	return m
}

func adaJadwalSekolah() bool { return len(jadwalSekolahSemua()) > 0 }

// jadwalSekolah: jadwal jenjang sekolah untuk hari kerja & hari (format sama dengan absenData.reguler).
func jadwalSekolah(unit string, hariKerja int, hari, aksi string) (jadwalResult, bool) {
	k := jenjangUnit(unit)
	if k == "" {
		return jadwalResult{}, false
	}
	j, ok := jadwalSekolahSemua()[k+"|"+strconv.Itoa(hariKerja)+"|"+hari]
	if !ok {
		return jadwalResult{}, false
	}
	if aksi == "pulang" {
		return jadwalResult{Found: true, Begin: j.BPulang, End: j.TPulang, Masuk: j.BPulang}, true
	}
	return jadwalResult{Found: true, Begin: j.BMasuk, End: j.TMasuk, Masuk: j.Masuk}, true
}

// jadwalReguler: mesin absen - guru/tendik memakai jadwal jenjang sekolahnya bila ada, selain itu jadwal APEL.
func (ctx absenCtx) jadwalReguler(hariKerja int, aksi string) (jadwalResult, error) {
	if adaJadwalSekolah() && ecarakdekNipModul(ctx.nip) {
		if j, ok := jadwalSekolah(ctx.unit, hariKerja, ctx.hari, aksi); ok {
			return j, nil
		}
		return regulerCadangan(hariKerja, func(hk int) (jadwalResult, error) { return absenData.reguler(hk, ctx.hari, aksi) })
	}
	return absenData.reguler(hariKerja, ctx.hari, aksi)
}

// regulerCadangan: hari yang tidak diisi di jadwal jenjang memakai jadwal reguler APEL. Sekolah 6 hari
// (pola 6HK) sementara APEL hanya punya reguler 5 hari: Senin-Jumat memakai reguler 5 hari.
func regulerCadangan(hk int, cari func(hk int) (jadwalResult, error)) (jadwalResult, error) {
	j, err := cari(hk)
	if err == nil && !j.Found && hk == 6 {
		return cari(5)
	}
	return j, err
}

// regulerPeg: rekap - sama dengan jadwalReguler, dengan cache per bulan.
func (b *rekapBulan) regulerPeg(nip, unit string, hk int, hari, aksi string) jadwalResult {
	if b.pegSekolah[nip] {
		if j, ok := jadwalSekolah(unit, hk, hari, aksi); ok {
			return j
		}
		j, _ := regulerCadangan(hk, func(h int) (jadwalResult, error) { return b.reguler(h, hari, aksi), nil })
		return j
	}
	return b.reguler(hk, hari, aksi)
}

/* ------------------------------------------------------
   HARI KERJA SEKOLAH (5/6 hari) mengikuti Jadwal Absensi jenjang.
   Jadwal jenjang dicari per hari kerja pola unit (APEL pola_kerja_unit, umumnya 5HK). Bila jenjang
   sekolah hanya punya jadwal satu jenis hari kerja (mis. hanya "SMP 6 hari"), pola reguler sekolah
   memakai pola reguler dengan hari kerja itu (6HK): Sabtu menjadi hari kerja di mesin absen & rekap.
   Bila jenjang punya jadwal 5 DAN 6 hari, pola unit di APEL yang menentukan.
   Dipasang pada absenData.polaUnit (absen & rekap per pegawai) dan muatKonteksRekap (rekap massal).
------------------------------------------------------ */

// hariKerjaJenjang: hari kerja satu-satunya jadwal jenjang (TK/SD/SMP); 0 = tidak ada / lebih dari satu.
func hariKerjaJenjang(jenjang string) int {
	hk := 0
	for kunci := range jadwalSekolahSemua() {
		bagian := strings.SplitN(kunci, "|", 3)
		if len(bagian) != 3 || bagian[0] != jenjang {
			continue
		}
		n, _ := strconv.Atoi(bagian[1])
		if hk != 0 && n != hk {
			return 0
		}
		hk = n
	}
	return hk
}

var (
	polaSemuaMu  sync.Mutex
	polaSemuaExp time.Time
	polaSemuaMap map[string]polaKerja
)

// polaSemua: absenData.polaAll dengan cache 60 detik (dipanggil per unit).
func polaSemua() map[string]polaKerja {
	polaSemuaMu.Lock()
	defer polaSemuaMu.Unlock()
	if polaSemuaMap == nil || time.Now().After(polaSemuaExp) {
		if all, err := absenData.polaAll(); err == nil {
			polaSemuaMap, polaSemuaExp = all, time.Now().Add(60*time.Second)
		}
	}
	return polaSemuaMap
}

// polaHariKerjaSekolah: ganti pola reguler yang hari kerjanya berbeda dari jadwal jenjang sekolah.
func polaHariKerjaSekolah(jenjang string, codes []string) []string {
	hk := hariKerjaJenjang(jenjang)
	if hk == 0 || len(codes) == 0 {
		return codes
	}
	all := polaSemua()
	ganti := ""
	if p, ok := all[strconv.Itoa(hk)+"HK"]; ok && p.reguler() && p.HariKerja == hk {
		ganti = p.Kode
	} else {
		for _, p := range all {
			if p.reguler() && p.HariKerja == hk && (ganti == "" || p.Kode < ganti) {
				ganti = p.Kode
			}
		}
	}
	if ganti == "" {
		return codes
	}
	out := make([]string, 0, len(codes))
	for _, c := range codes {
		if p, ok := all[c]; ok && p.reguler() && p.HariKerja != hk {
			c = ganti
		}
		dobel := false
		for _, x := range out {
			dobel = dobel || x == c
		}
		if !dobel {
			out = append(out, c)
		}
	}
	return out
}

func init() {
	polaUnitAsli := absenData.polaUnit
	absenData.polaUnit = func(unit string) ([]string, error) {
		codes, err := polaUnitAsli(unit)
		if err != nil || !adaJadwalSekolah() {
			return codes, err
		}
		jenjang := jenjangUnit(unit)
		if jenjang == "" || hariKerjaJenjang(jenjang) == 0 {
			return codes, nil
		}
		if len(codes) == 0 { // sekolah tanpa pola sendiri: pola induk (seperti polaUnitBerjenjang)
			for _, u := range absenData.unitChain(unit)[1:] {
				if codes, err = polaUnitAsli(u); err != nil {
					return nil, err
				}
				if len(codes) > 0 {
					break
				}
			}
		}
		return polaHariKerjaSekolah(jenjang, codes), nil
	}

	// Rekap: pegawai sekolah tercatat di SIMPEG dengan unit = OPD (Dinas) dan subunit = sekolah.
	// Mesin absen & titik presensi memakai unit sekolah; rekap disamakan agar pola kerja (APEL / jadwal
	// jenjang e-Carakdek) sekolah ikut terbaca, bukan pola OPD induk.
	rekapPegawaiAsli := rekapData.pegawai
	rekapData.pegawai = func(unit, nip string) ([]rekapPegawai, error) {
		list, err := rekapPegawaiAsli(unit, nip)
		for i := range list {
			if jenjangUnit(list[i].Unit) != "" {
				continue
			}
			s := list[i].SubUnit
			if (s == "" || jenjangUnit(s) == "") && nip != "" {
				s = unitByNip(list[i].NIP) // satu pegawai (portal): unit yang sama dengan mesin absen
			}
			if s != "" && s != list[i].Unit && jenjangUnit(s) != "" {
				list[i].Unit = s
			}
		}
		return list, err
	}

	muatAsli := muatKonteksRekap
	muatKonteksRekap = func() (*konteksRekap, error) {
		k, err := muatAsli()
		if err != nil || k == nil || !adaJadwalSekolah() {
			return k, err
		}
		for id, jenjang := range unitSekolah() {
			if hariKerjaJenjang(jenjang) != 0 {
				if codes := k.polaBerjenjang(id); len(codes) > 0 {
					k.pola[id] = polaHariKerjaSekolah(jenjang, codes)
				}
			}
		}
		return k, nil
	}
}

// unitSekolah: id unit -> jenjang (TK/SD/SMP) untuk unit yang namanya nama sekolah (satu query tbl_unit).
var unitSekolah = func() map[string]string {
	out := map[string]string{}
	if db.DB == nil {
		return out
	}
	rows, err := db.DB.Query(`SELECT CAST(id_unit AS CHAR), COALESCE(nmunit,'') FROM tbl_unit`)
	if err != nil {
		return out
	}
	defer rows.Close()
	for rows.Next() {
		var id, nama string
		if rows.Scan(&id, &nama) != nil {
			continue
		}
		switch {
		case jenjangTK.MatchString(nama):
			out[id] = "TK"
		case jenjangSD.MatchString(nama):
			out[id] = "SD"
		case jenjangSMP.MatchString(nama):
			out[id] = "SMP"
		}
	}
	return out
}

// GET /absen/jadwal - semua Jadwal Absensi APEL; yang berkategori TK/SD/SMP dapat dikelola super admin.
// Admin sekolah: hanya jadwal jenjang sekolahnya (TK/SD/SMP); jadwal reguler APEL tidak ditampilkan.
func ecarakdekJadwalList(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	rows, err := db.DBa.Query(`SELECT id, nama, kategori, hari_kerja FROM shift_master ORDER BY FIELD(kategori,'reguler','TK','SD','SMP') DESC, kategori, hari_kerja, nama`)
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Jadwal APEL tidak dapat dibaca"})
		return
	}
	master, idx := []gin.H{}, map[string]gin.H{}
	for rows.Next() {
		var id, nama, kategori string
		var hk int
		if rows.Scan(&id, &nama, &kategori, &hk) == nil {
			m := gin.H{"id": id, "nama": nama, "kategori": kategori, "hari_kerja": hk, "boleh": kategoriSekolahValid(kategori), "detail": []gin.H{}}
			master, idx[id] = append(master, m), m
		}
	}
	rows.Close()
	if d, err := db.DBa.Query(`SELECT id, shift_id, hari, TIME_FORMAT(bmasuk,'%H:%i'), TIME_FORMAT(tmasuk,'%H:%i'), TIME_FORMAT(masuk,'%H:%i'),
		TIME_FORMAT(bpulang,'%H:%i'), TIME_FORMAT(tpulang,'%H:%i') FROM shift_detail
		ORDER BY FIELD(hari,'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu')`); err == nil {
		for d.Next() {
			var id, sid, hari string
			var bm, tm, m, bp, tp sql.NullString
			if d.Scan(&id, &sid, &hari, &bm, &tm, &m, &bp, &tp) == nil && idx[sid] != nil {
				idx[sid]["detail"] = append(idx[sid]["detail"].([]gin.H), gin.H{"id": id, "hari": hari, "bmasuk": bm.String, "tmasuk": tm.String,
					"masuk": m.String, "bpulang": bp.String, "tpulang": tp.String})
			}
		}
		d.Close()
	}
	for _, m := range master {
		m["ramadhan"] = []gin.H{}
	}
	if k, err := db.DBa.Query(`SELECT id, shift_id, hari, TIME_FORMAT(bmasuk,'%H:%i'), TIME_FORMAT(tmasuk,'%H:%i'), TIME_FORMAT(masuk,'%H:%i'),
		TIME_FORMAT(bpulang,'%H:%i'), TIME_FORMAT(tpulang,'%H:%i') FROM shift_khusus
		ORDER BY FIELD(hari,'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu')`); err == nil {
		for k.Next() {
			var id, sid, hari string
			var bm, tm, m, bp, tp sql.NullString
			if k.Scan(&id, &sid, &hari, &bm, &tm, &m, &bp, &tp) == nil && idx[sid] != nil {
				idx[sid]["ramadhan"] = append(idx[sid]["ramadhan"].([]gin.H), gin.H{"id": id, "hari": hari, "bmasuk": bm.String, "tmasuk": tm.String,
					"masuk": m.String, "bpulang": bp.String, "tpulang": tp.String})
			}
		}
		k.Close()
	}
	jenjang := ""
	if s.Role != asn.EcarakdekSuperAdmin {
		jenjang = jenjangUnit(s.Unit)
		pilih := []gin.H{}
		for _, m := range master {
			if jenjang != "" && m["kategori"] == jenjang {
				pilih = append(pilih, m)
			}
		}
		master = pilih
	}
	c.JSON(http.StatusOK, gin.H{"master": master, "kategori": kategoriSekolah, "jenjang": jenjang})
}

// kategoriMaster: kategori shift_master; ok=false (sudah ditulis respons) bila tidak ada / bukan TK/SD/SMP.
func kategoriMaster(c *gin.Context, id string) (string, bool) {
	var k string
	if db.DBa.QueryRow(`SELECT kategori FROM shift_master WHERE id = ?`, id).Scan(&k) != nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "Jadwal tidak ditemukan"})
		return "", false
	}
	if !kategoriSekolahValid(k) {
		c.JSON(http.StatusForbidden, gin.H{"error": "Jadwal kategori " + k + " hanya dapat diubah di APEL"})
		return "", false
	}
	return k, true
}

// POST /absen/jadwal-master, PUT /absen/jadwal-master/:id {nama, kategori: TK|SD|SMP, hari_kerja: 5|6}
func ecarakdekJadwalMasterSimpan(c *gin.Context) {
	var r struct {
		Nama      string `json:"nama"`
		Kategori  string `json:"kategori"`
		HariKerja int    `json:"hari_kerja"`
	}
	if c.ShouldBindJSON(&r) != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Data tidak valid"})
		return
	}
	r.Nama, r.Kategori = strings.TrimSpace(r.Nama), strings.ToUpper(strings.TrimSpace(r.Kategori))
	switch {
	case r.Nama == "" || len(r.Nama) > 100:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Nama jadwal wajib diisi (maks 100 karakter)"})
		return
	case !kategoriSekolahValid(r.Kategori):
		c.JSON(http.StatusBadRequest, gin.H{"error": "Kategori harus TK, SD, atau SMP"})
		return
	case r.HariKerja != 5 && r.HariKerja != 6:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Hari kerja harus 5 atau 6"})
		return
	}
	id := strings.TrimSpace(c.Param("id"))
	if id != "" {
		if _, ok := kategoriMaster(c, id); !ok {
			return
		}
	}
	var lain string
	if db.DBa.QueryRow(`SELECT id FROM shift_master WHERE kategori = ? AND hari_kerja = ? AND id <> ? LIMIT 1`, r.Kategori, r.HariKerja, id).Scan(&lain) == nil {
		c.JSON(http.StatusConflict, gin.H{"error": "Jadwal " + r.Kategori + " " + strconv.Itoa(r.HariKerja) + " hari kerja sudah ada; ubah jadwal tersebut"})
		return
	}
	var err error
	if id == "" {
		id = uuid.New().String()
		_, err = db.DBa.Exec(`INSERT INTO shift_master (id, nama, kategori, hari_kerja) VALUES (?,?,?,?)`, id, r.Nama, r.Kategori, r.HariKerja)
	} else {
		_, err = db.DBa.Exec(`UPDATE shift_master SET nama = ?, kategori = ?, hari_kerja = ? WHERE id = ?`, r.Nama, r.Kategori, r.HariKerja, id)
	}
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menyimpan jadwal"})
		return
	}
	jadwalSekolahReset()
	c.JSON(http.StatusOK, gin.H{"success": true, "id": id, "message": "Jadwal disimpan"})
}

// DELETE /absen/jadwal-master/:id - hapus jadwal TK/SD/SMP beserta rincian harinya.
func ecarakdekJadwalMasterHapus(c *gin.Context) {
	id := c.Param("id")
	if _, ok := kategoriMaster(c, id); !ok {
		return
	}
	tx, err := db.DBa.Begin()
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menghapus"})
		return
	}
	defer tx.Rollback()
	for _, q := range []string{`DELETE FROM shift_detail WHERE shift_id = ?`, `DELETE FROM shift_khusus WHERE shift_id = ?`, `DELETE FROM shift_master WHERE id = ?`} {
		if _, err = tx.Exec(q, id); err != nil {
			c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menghapus"})
			return
		}
	}
	if err = tx.Commit(); err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menghapus"})
		return
	}
	jadwalSekolahReset()
	c.JSON(http.StatusOK, gin.H{"success": true, "message": "Jadwal dihapus; guru jenjang ini kembali memakai jadwal reguler APEL"})
}

// jadwalHariTabel: "detail" = shift_detail (jadwal biasa), "ramadhan" = shift_khusus (Jadwal Ramadhan, sama dengan APEL).
func jadwalHariTabel(jenis string) string {
	if jenis == "ramadhan" {
		return "shift_khusus"
	}
	return "shift_detail"
}

// POST /absen/jadwal-:jenis, PUT /absen/jadwal-:jenis/:id {shift_id, hari, bmasuk, masuk, tmasuk, bpulang, tpulang}
// jenis: detail (jadwal) | ramadhan (jadwal Ramadhan). Hanya untuk jadwal kategori TK/SD/SMP.
func ecarakdekJadwalHariSimpan(jenis string) gin.HandlerFunc {
	tabel := jadwalHariTabel(jenis)
	return func(c *gin.Context) {
		var r struct {
			ShiftID string `json:"shift_id"`
			Hari    string `json:"hari"`
			BMasuk  string `json:"bmasuk"`
			TMasuk  string `json:"tmasuk"`
			Masuk   string `json:"masuk"`
			BPulang string `json:"bpulang"`
			TPulang string `json:"tpulang"`
		}
		if c.ShouldBindJSON(&r) != nil {
			c.JSON(http.StatusBadRequest, gin.H{"error": "Data tidak valid"})
			return
		}
		id := strings.TrimSpace(c.Param("id"))
		if id != "" {
			// baris yang diubah harus milik jadwal TK/SD/SMP; jadwal induknya tidak dapat dipindah
			if db.DBa.QueryRow(`SELECT shift_id FROM `+tabel+` WHERE id = ?`, id).Scan(&r.ShiftID) != nil {
				c.JSON(http.StatusNotFound, gin.H{"error": "Rincian jadwal tidak ditemukan"})
				return
			}
		}
		if _, ok := kategoriMaster(c, r.ShiftID); !ok {
			return
		}
		hariOK := false
		for _, h := range namaHari {
			if h == r.Hari {
				hariOK = true
			}
		}
		jam := []string{r.BMasuk, r.Masuk, r.TMasuk, r.BPulang, r.TPulang}
		for i := range jam {
			jam[i] = strings.TrimSpace(jam[i])
			if len(jam[i]) == 8 {
				jam[i] = jam[i][:5] // HH:MM:SS -> HH:MM
			}
			if !jamPola.MatchString(jam[i]) {
				c.JSON(http.StatusBadRequest, gin.H{"error": "Format jam harus JJ:MM"})
				return
			}
		}
		switch {
		case !hariOK:
			c.JSON(http.StatusBadRequest, gin.H{"error": "Hari tidak dikenal"})
			return
		case !(jam[0] <= jam[1] && jam[1] <= jam[2] && jam[2] < jam[3] && jam[3] < jam[4]):
			c.JSON(http.StatusBadRequest, gin.H{"error": "Urutan jam harus: mulai absen masuk ≤ jam masuk ≤ batas masuk < jam pulang < batas pulang"})
			return
		}
		var dupe string
		if db.DBa.QueryRow(`SELECT id FROM `+tabel+` WHERE shift_id = ? AND hari = ? AND id <> ?`, r.ShiftID, r.Hari, id).Scan(&dupe) == nil {
			c.JSON(http.StatusConflict, gin.H{"error": "Hari " + r.Hari + " sudah ada di jadwal ini; ubah baris tersebut"})
			return
		}
		var err error
		if id == "" {
			_, err = db.DBa.Exec(`INSERT INTO `+tabel+` (id, shift_id, hari, bmasuk, tmasuk, masuk, bpulang, tpulang) VALUES (?,?,?,?,?,?,?,?)`,
				uuid.New().String(), r.ShiftID, r.Hari, jam[0], jam[2], jam[1], jam[3], jam[4])
		} else {
			_, err = db.DBa.Exec(`UPDATE `+tabel+` SET hari = ?, bmasuk = ?, tmasuk = ?, masuk = ?, bpulang = ?, tpulang = ? WHERE id = ?`,
				r.Hari, jam[0], jam[2], jam[1], jam[3], jam[4], id)
		}
		if err != nil {
			c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menyimpan"})
			return
		}
		jadwalSekolahReset()
		c.JSON(http.StatusOK, gin.H{"success": true, "message": "Jadwal hari " + r.Hari + " disimpan"})
	}
}

// DELETE /absen/jadwal-:jenis/:id
func ecarakdekJadwalHariHapus(jenis string) gin.HandlerFunc {
	tabel := jadwalHariTabel(jenis)
	return func(c *gin.Context) {
		var sid string
		if db.DBa.QueryRow(`SELECT shift_id FROM `+tabel+` WHERE id = ?`, c.Param("id")).Scan(&sid) != nil {
			c.JSON(http.StatusNotFound, gin.H{"error": "Rincian jadwal tidak ditemukan"})
			return
		}
		if _, ok := kategoriMaster(c, sid); !ok {
			return
		}
		if _, err := db.DBa.Exec(`DELETE FROM `+tabel+` WHERE id = ?`, c.Param("id")); err != nil {
			c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menghapus"})
			return
		}
		jadwalSekolahReset()
		c.JSON(http.StatusOK, gin.H{"success": true, "message": "Hari dihapus dari jadwal"})
	}
}

/* ======================================================
   ABSENSI TITIPAN (lokasi_penugasan) - pengajuan ke APEL
====================================================== */

// ecarakdekNipDalamScope: NIP -> PNS_ID untuk NIP yang termasuk cakupan akun.
func ecarakdekNipDalamScope(s asn.EcarakdekScope, nips []string) map[string]bool {
	out := map[string]bool{}
	byID := map[string]string{}
	ids := []string{}
	for _, part := range potong(nips, 500) {
		rows, err := db.DB.Query(`SELECT id, nip FROM pns_list_cache WHERE nip IN (`+tanda(len(part))+`)`, toArgs(part)...)
		if err != nil {
			continue
		}
		for rows.Next() {
			var id, nip string
			if rows.Scan(&id, &nip) == nil {
				byID[id] = nip
				ids = append(ids, id)
			}
		}
		rows.Close()
	}
	for id := range asn.EcarakdekSaringPns(s, ids) {
		out[byID[id]] = true
	}
	return out
}

// GET /absen/titipan - titipan aktif/terjadwal ASN dalam cakupan + daftar pengajuan.
func ecarakdekTitipanList(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	rows, err := db.DBa.Query(`SELECT l.id, COALESCE(g.nama, l.nama), COALESCE(g.lat + 0, l.lat), COALESCE(g.lng + 0, l.lng), COALESCE(g.radius, l.radius),
		COALESCE(l.geolokasi_id,''), p.nip, DATE_FORMAT(p.mulai,'%Y-%m-%d'), COALESCE(DATE_FORMAT(p.selesai,'%Y-%m-%d'),'')
		FROM lokasi_penugasan_pegawai p JOIN lokasi_penugasan l ON l.id = p.lokasi_id LEFT JOIN geolokasi g ON g.id = l.geolokasi_id ORDER BY p.nip`)
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Absensi titipan tidak dapat dibaca"})
		return
	}
	hari := time.Now().In(witaZone).Format("2006-01-02")
	semua, nips := []gin.H{}, []string{}
	for rows.Next() {
		var id int64
		var nama, geo, nip, mulai, selesai string
		var lat, lng float64
		var radius int
		if rows.Scan(&id, &nama, &lat, &lng, &radius, &geo, &nip, &mulai, &selesai) == nil {
			semua = append(semua, gin.H{"lokasi_id": id, "lokasi": nama, "lat": lat, "lng": lng, "radius": radius, "geolokasi_id": geo, "nip": nip,
				"mulai": mulai, "selesai": selesai, "aktif": mulai <= hari && (selesai == "" || selesai >= hari), "berakhir": selesai != "" && selesai < hari})
			nips = append(nips, nip)
		}
	}
	rows.Close()

	usulan := []gin.H{}
	if r2, err := db.DBa.Query(`SELECT id, aksi, nip, COALESCE(lokasi_id,0), COALESCE(geolokasi_id,''), COALESCE(DATE_FORMAT(mulai,'%Y-%m-%d'),''),
		COALESCE(DATE_FORMAT(selesai,'%Y-%m-%d'),''), alasan, status, diajukan_oleh, DATE_FORMAT(diajukan_pada,'%Y-%m-%d %H:%i'), COALESCE(diproses_oleh,''),
		COALESCE(DATE_FORMAT(diproses_pada,'%Y-%m-%d %H:%i'),''), COALESCE(catatan,'') FROM lokasi_penugasan_usulan ORDER BY diajukan_pada DESC LIMIT 500`); err == nil {
		for r2.Next() {
			var id, aksi, nip, geo, mulai, selesai, alasan, st, oleh, pada, proses, prosesPada, catatan string
			var lokasi int64
			if r2.Scan(&id, &aksi, &nip, &lokasi, &geo, &mulai, &selesai, &alasan, &st, &oleh, &pada, &proses, &prosesPada, &catatan) == nil {
				usulan = append(usulan, gin.H{"id": id, "aksi": aksi, "nip": nip, "lokasi_id": lokasi, "geolokasi_id": geo, "mulai": mulai, "selesai": selesai,
					"alasan": alasan, "status": st, "diajukan_oleh": oleh, "diajukan_pada": pada, "diproses_oleh": proses, "diproses_pada": prosesPada, "catatan": catatan})
				nips = append(nips, nip)
			}
		}
		r2.Close()
	}

	boleh := ecarakdekNipDalamScope(s, nips)
	info := muatInfoPegawai(nips)
	geoIDs := []string{}
	for _, u := range usulan {
		if g := u["geolokasi_id"].(string); g != "" {
			geoIDs = append(geoIDs, g)
		}
	}
	geoNama := map[string]string{}
	if len(geoIDs) > 0 {
		if r3, err := db.DBa.Query(`SELECT id, nama FROM geolokasi WHERE id IN (`+tanda(len(geoIDs))+`)`, toArgs(geoIDs)...); err == nil {
			for r3.Next() {
				var id, nama string
				if r3.Scan(&id, &nama) == nil {
					geoNama[id] = nama
				}
			}
			r3.Close()
		}
	}
	saring := func(list []gin.H) []gin.H {
		out := []gin.H{}
		for _, d := range list {
			n := d["nip"].(string)
			if !boleh[n] {
				continue
			}
			d["nama"], d["unit_nama"] = firstNonEmpty(info[n].Nama, n), info[n].UnitNama
			if g, ok := d["geolokasi_id"].(string); ok && g != "" && d["lokasi"] == nil {
				d["lokasi"] = geoNama[g]
			}
			out = append(out, d)
		}
		return out
	}
	penugasan := saring(semua)
	sort.SliceStable(penugasan, func(i, j int) bool { return penugasan[i]["nama"].(string) < penugasan[j]["nama"].(string) })
	c.JSON(http.StatusOK, gin.H{"penugasan": penugasan, "usulan": saring(usulan)})
}

// GET /absen/titipan/pegawai?q= (juga /absen/pegawai-cari) - cari ASN sekolah dalam cakupan (nama / NIP).
func ecarakdekTitipanPegawai(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	q := strings.ToLower(strings.TrimSpace(c.Query("q")))
	if len([]rune(q)) < 3 {
		c.JSON(http.StatusOK, gin.H{"data": []gin.H{}})
		return
	}
	list, err := asn.EcarakdekPegawaiDaftar(s, "")
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Daftar pegawai tidak dapat dibaca"})
		return
	}
	out := []gin.H{}
	for _, p := range list {
		if nipPola.MatchString(p.NIP) && (strings.Contains(strings.ToLower(p.Nama), q) || strings.Contains(p.NIP, q)) {
			out = append(out, gin.H{"nip": p.NIP, "pns_id": p.PnsID, "nama": p.Nama, "unit_nama": p.UnitNama, "jabatan": p.Jabatan})
			if len(out) >= 30 {
				break
			}
		}
	}
	c.JSON(http.StatusOK, gin.H{"data": out})
}

// POST /absen/titipan-usulan (super admin dinas) {aksi: tambah|ubah|hapus, nip, lokasi_id, geolokasi_id, mulai, selesai, alasan}
func ecarakdekTitipanUsul(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	var r struct {
		Aksi     string `json:"aksi"`
		NIP      string `json:"nip"`
		LokasiID int64  `json:"lokasi_id"`
		Geo      string `json:"geolokasi_id"`
		Mulai    string `json:"mulai"`
		Selesai  string `json:"selesai"`
		Alasan   string `json:"alasan"`
	}
	if c.ShouldBindJSON(&r) != nil || !nipPola.MatchString(strings.TrimSpace(r.NIP)) {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Pilih pegawai"})
		return
	}
	r.NIP, r.Geo, r.Alasan = strings.TrimSpace(r.NIP), strings.TrimSpace(r.Geo), strings.TrimSpace(r.Alasan)
	if !ecarakdekNipDalamScope(s, []string{r.NIP})[r.NIP] {
		c.JSON(http.StatusForbidden, gin.H{"error": "Pegawai di luar cakupan akun"})
		return
	}
	if len([]rune(r.Alasan)) < 10 || len(r.Alasan) > 1000 {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Alasan wajib diisi (10–1000 karakter)"})
		return
	}
	var mulai, selesai interface{}
	switch r.Aksi {
	case "tambah", "ubah":
		m, e1 := time.Parse("2006-01-02", r.Mulai)
		if e1 != nil {
			c.JSON(http.StatusBadRequest, gin.H{"error": "Tanggal mulai tidak valid"})
			return
		}
		mulai = r.Mulai
		if r.Selesai != "" {
			sl, e2 := time.Parse("2006-01-02", r.Selesai)
			if e2 != nil || sl.Before(m) {
				c.JSON(http.StatusBadRequest, gin.H{"error": "Tanggal selesai tidak valid"})
				return
			}
			selesai = r.Selesai
		}
	case "hapus":
	default:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Jenis pengajuan tidak dikenal"})
		return
	}
	if r.Aksi != "tambah" {
		var x int
		if r.LokasiID <= 0 || db.DBa.QueryRow(`SELECT 1 FROM lokasi_penugasan_pegawai WHERE lokasi_id = ? AND nip = ?`, r.LokasiID, r.NIP).Scan(&x) != nil {
			c.JSON(http.StatusNotFound, gin.H{"error": "Absensi titipan pegawai ini tidak ditemukan"})
			return
		}
		if r.Aksi == "ubah" && r.Geo == "" {
			_ = db.DBa.QueryRow(`SELECT COALESCE(geolokasi_id,'') FROM lokasi_penugasan WHERE id = ?`, r.LokasiID).Scan(&r.Geo)
		}
	}
	var lokasiID interface{}
	if r.Aksi != "tambah" {
		lokasiID = r.LokasiID
	}
	var geo interface{}
	if r.Aksi != "hapus" {
		var x int
		if r.Geo == "" || db.DBa.QueryRow(`SELECT 1 FROM geolokasi WHERE id = ? AND jenis = 'presensi'`, r.Geo).Scan(&x) != nil {
			c.JSON(http.StatusBadRequest, gin.H{"error": "Pilih titik koordinat lokasi titipan"})
			return
		}
		geo = r.Geo
	}
	var menunggu int
	_ = db.DBa.QueryRow(`SELECT COUNT(*) FROM lokasi_penugasan_usulan WHERE nip = ? AND status = 'diajukan'`, r.NIP).Scan(&menunggu)
	if menunggu > 0 {
		c.JSON(http.StatusConflict, gin.H{"error": "Masih ada pengajuan titipan pegawai ini yang menunggu persetujuan APEL"})
		return
	}
	id := uuid.New().String()
	if _, err := db.DBa.Exec(`INSERT INTO lokasi_penugasan_usulan (id, aksi, nip, lokasi_id, geolokasi_id, mulai, selesai, alasan, status, diajukan_oleh, diajukan_pada)
		VALUES (?,?,?,?,?,?,?,?,'diajukan',?,NOW())`, id, r.Aksi, r.NIP, lokasiID, geo, mulai, selesai, r.Alasan, ecarakdekOleh(s)); err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Pengajuan gagal disimpan (tabel lokasi_penugasan_usulan sudah dibuat?)"})
		return
	}
	c.JSON(http.StatusCreated, gin.H{"success": true, "id": id, "message": "Pengajuan titipan terkirim; menunggu persetujuan admin APEL (BKPSDM)."})
}

// DELETE /absen/titipan-usulan/:id (super admin dinas) - batalkan pengajuan yang belum diproses.
func ecarakdekTitipanBatal(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	var nip string
	if db.DBa.QueryRow(`SELECT nip FROM lokasi_penugasan_usulan WHERE id = ? AND status = 'diajukan'`, c.Param("id")).Scan(&nip) != nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "Pengajuan tidak ditemukan atau sudah diproses"})
		return
	}
	if !ecarakdekNipDalamScope(s, []string{nip})[nip] {
		c.JSON(http.StatusForbidden, gin.H{"error": "Pegawai di luar cakupan akun"})
		return
	}
	if _, err := db.DBa.Exec(`UPDATE lokasi_penugasan_usulan SET status = 'dibatalkan', diproses_oleh = ?, diproses_pada = NOW() WHERE id = ? AND status = 'diajukan'`,
		ecarakdekOleh(s), c.Param("id")); err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal membatalkan"})
		return
	}
	c.JSON(http.StatusOK, gin.H{"success": true, "message": "Pengajuan dibatalkan"})
}

/* ---------- APEL (super admin BKPSDM): persetujuan titipan dari e-Carakdek ---------- */

// GET /titipan-usulan?status=
func titipanUsulanApel(c *gin.Context) {
	if _, _, _, ok := apelAdmin(c, true); !ok {
		return
	}
	status := c.DefaultQuery("status", "diajukan")
	rows, err := db.DBa.Query(`SELECT u.id, u.aksi, u.nip, COALESCE(u.lokasi_id,0), COALESCE(u.geolokasi_id,''), COALESCE(g.nama,''), COALESCE(g.id_unit,''),
		COALESCE(DATE_FORMAT(u.mulai,'%Y-%m-%d'),''), COALESCE(DATE_FORMAT(u.selesai,'%Y-%m-%d'),''), u.alasan, u.status, u.diajukan_oleh,
		DATE_FORMAT(u.diajukan_pada,'%Y-%m-%d %H:%i'), COALESCE(u.diproses_oleh,''), COALESCE(DATE_FORMAT(u.diproses_pada,'%Y-%m-%d %H:%i'),''), COALESCE(u.catatan,''),
		COALESCE(lg.nama, l.nama, ''), COALESCE(DATE_FORMAT(p.mulai,'%Y-%m-%d'),''), COALESCE(DATE_FORMAT(p.selesai,'%Y-%m-%d'),'')
		FROM lokasi_penugasan_usulan u LEFT JOIN geolokasi g ON g.id = u.geolokasi_id
		LEFT JOIN lokasi_penugasan l ON l.id = u.lokasi_id LEFT JOIN geolokasi lg ON lg.id = l.geolokasi_id
		LEFT JOIN lokasi_penugasan_pegawai p ON p.lokasi_id = u.lokasi_id AND p.nip = u.nip
		WHERE u.status = ? ORDER BY u.diajukan_pada DESC LIMIT 500`, status)
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Pengajuan titipan belum siap (tabel lokasi_penugasan_usulan)"})
		return
	}
	defer rows.Close()
	list, nips, units := []gin.H{}, []string{}, []string{}
	for rows.Next() {
		var id, aksi, nip, geo, geoNama, geoUnit, mulai, selesai, alasan, st, oleh, pada, proses, prosesPada, catatan, lamaNama, lamaMulai, lamaSelesai string
		var lokasi int64
		if rows.Scan(&id, &aksi, &nip, &lokasi, &geo, &geoNama, &geoUnit, &mulai, &selesai, &alasan, &st, &oleh, &pada, &proses, &prosesPada, &catatan,
			&lamaNama, &lamaMulai, &lamaSelesai) == nil {
			list = append(list, gin.H{"id": id, "aksi": aksi, "nip": nip, "lokasi_id": lokasi, "geolokasi_id": geo, "lokasi": geoNama, "lokasi_unit": geoUnit,
				"mulai": mulai, "selesai": selesai, "alasan": alasan, "status": st, "diajukan_oleh": oleh, "diajukan_pada": pada, "diproses_oleh": proses,
				"diproses_pada": prosesPada, "catatan": catatan, "lama": gin.H{"lokasi": lamaNama, "mulai": lamaMulai, "selesai": lamaSelesai}})
			nips, units = append(nips, nip), append(units, geoUnit)
		}
	}
	info, nu := muatInfoPegawai(nips), namaUnit(units)
	for _, d := range list {
		n := d["nip"].(string)
		d["nama"], d["unit_nama"] = firstNonEmpty(info[n].Nama, n), info[n].UnitNama
		d["lokasi_unit_nama"] = nu[d["lokasi_unit"].(string)]
	}
	c.JSON(http.StatusOK, gin.H{"data": list})
}

// PUT /titipan-usulan/:id/status {status: disetujui|ditolak, catatan}
// disetujui -> diterapkan ke lokasi_penugasan / lokasi_penugasan_pegawai (sama dengan menu Lokasi Absen Tambahan APEL).
func titipanUsulanApelStatus(c *gin.Context) {
	username, _, _, ok := apelAdmin(c, true)
	if !ok {
		return
	}
	var req struct {
		Status  string `json:"status"`
		Catatan string `json:"catatan"`
	}
	if c.ShouldBindJSON(&req) != nil || (req.Status != "disetujui" && req.Status != "ditolak") {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Status harus disetujui atau ditolak"})
		return
	}
	req.Catatan = strings.TrimSpace(req.Catatan)
	if req.Status == "ditolak" && len([]rune(req.Catatan)) < 5 {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Catatan penolakan wajib diisi (minimal 5 karakter)"})
		return
	}
	id := c.Param("id")
	tx, err := db.DBa.Begin()
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menyimpan"})
		return
	}
	defer tx.Rollback()
	var aksi, nip, geo string
	var lokasiLama int64
	var mulai, selesai sql.NullString
	if tx.QueryRow(`SELECT aksi, nip, COALESCE(lokasi_id,0), COALESCE(geolokasi_id,''), DATE_FORMAT(mulai,'%Y-%m-%d'), DATE_FORMAT(selesai,'%Y-%m-%d')
		FROM lokasi_penugasan_usulan WHERE id = ? AND status = 'diajukan' FOR UPDATE`, id).Scan(&aksi, &nip, &lokasiLama, &geo, &mulai, &selesai) != nil {
		c.JSON(http.StatusConflict, gin.H{"error": "Pengajuan tidak ditemukan atau sudah diproses"})
		return
	}
	if req.Status == "disetujui" {
		if msg, err := terapkanTitipan(tx, aksi, nip, lokasiLama, geo, mulai, selesai, username); err != nil || msg != "" {
			if msg == "" {
				msg = "Gagal menerapkan absensi titipan"
			}
			c.JSON(http.StatusConflict, gin.H{"error": msg})
			return
		}
	}
	if _, err = tx.Exec(`UPDATE lokasi_penugasan_usulan SET status = ?, catatan = ?, diproses_oleh = ?, diproses_pada = NOW() WHERE id = ?`,
		req.Status, req.Catatan, username, id); err == nil {
		err = tx.Commit()
	}
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menyimpan"})
		return
	}
	c.JSON(http.StatusOK, gin.H{"success": true, "status": req.Status, "message": "Pengajuan titipan " + req.Status + "."})
}

// terapkanTitipan: logika lokasiPenugasanTugas / lokasiPenugasanPegawai (DELETE) di dalam transaksi.
// msg != "" = pengajuan tidak bisa diterapkan (mis. titik sudah dihapus).
func terapkanTitipan(tx *sql.Tx, aksi, nip string, lokasiLama int64, geo string, mulai, selesai sql.NullString, oleh string) (string, error) {
	hapusPegawai := func(lokasiID int64) error {
		if _, err := tx.Exec(`DELETE FROM lokasi_penugasan_pegawai WHERE lokasi_id = ? AND nip = ?`, lokasiID, nip); err != nil {
			return err
		}
		var sisa int
		if tx.QueryRow(`SELECT COUNT(*) FROM lokasi_penugasan_pegawai WHERE lokasi_id = ?`, lokasiID).Scan(&sisa) == nil && sisa == 0 {
			_, _ = tx.Exec(`DELETE FROM lokasi_penugasan WHERE id = ?`, lokasiID) // lokasi tanpa pegawai tidak disimpan
		}
		return nil
	}
	if aksi == "hapus" {
		return "", hapusPegawai(lokasiLama)
	}
	l, err := rekapData.pegawai("", nip)
	if err != nil || len(l) == 0 || l[0].Unit == "" {
		return "Pegawai tidak ditemukan", nil
	}
	unit := l[0].Unit
	var x int
	if geo == "" || tx.QueryRow(`SELECT 1 FROM geolokasi WHERE id = ?`, geo).Scan(&x) != nil {
		return "Titik koordinat pengajuan sudah tidak ada", nil
	}
	var lokasiID int64
	// satu lokasi per titik per OPD (dipakai bersama)
	if tx.QueryRow(`SELECT id FROM lokasi_penugasan WHERE id_unit = ? AND geolokasi_id = ? LIMIT 1`, unit, geo).Scan(&lokasiID) != nil {
		res, err := tx.Exec(`INSERT INTO lokasi_penugasan (nama, lat, lng, radius, id_unit, geolokasi_id, dibuat_oleh, diubah_pada)
			SELECT nama, lat + 0, lng + 0, COALESCE(radius,100), ?, id, ?, NOW() FROM geolokasi WHERE id = ?`, unit, oleh, geo)
		if err != nil {
			return "", err
		}
		lokasiID, _ = res.LastInsertId()
	}
	if aksi == "ubah" && lokasiLama > 0 && lokasiLama != lokasiID {
		if err := hapusPegawai(lokasiLama); err != nil {
			return "", err
		}
	}
	var sl interface{}
	if selesai.Valid && selesai.String != "" {
		sl = selesai.String
	}
	_, err = tx.Exec(`INSERT INTO lokasi_penugasan_pegawai (lokasi_id, nip, mulai, selesai, oleh, pada) VALUES (?,?,?,?,?,NOW())
		ON DUPLICATE KEY UPDATE mulai = VALUES(mulai), selesai = VALUES(selesai), oleh = VALUES(oleh), pada = NOW()`, lokasiID, nip, mulai.String, sl, oleh)
	return "", err
}

/* ======================================================
   CUTI & DINAS LUAR diinput admin (super admin dinas & admin sekolah) untuk ASN dalam cakupan:
   langsung DISETUJUI (sama dengan admin di APEL: lain.CreateCuti status disetujui / CreateDinas).
====================================================== */

// POST /absen/cuti {pns_id, jenis_cuti, tanggal_mulai, tanggal_selesai, alasan}
func ecarakdekCutiInput(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	var req struct {
		PnsID   string `json:"pns_id"`
		Jenis   string `json:"jenis_cuti"`
		Mulai   string `json:"tanggal_mulai"`
		Selesai string `json:"tanggal_selesai"`
		Alasan  string `json:"alasan"`
	}
	if c.ShouldBindJSON(&req) != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Data tidak valid"})
		return
	}
	req.PnsID, req.Alasan = strings.TrimSpace(req.PnsID), strings.TrimSpace(req.Alasan)
	if req.PnsID == "" || !asn.EcarakdekSaringPns(s, []string{req.PnsID})[req.PnsID] {
		c.JSON(http.StatusForbidden, gin.H{"error": "Pilih pegawai sekolah dalam cakupan akun"})
		return
	}
	known := false
	for _, j := range jenisCuti {
		known = known || j == req.Jenis
	}
	a, e1 := time.Parse("2006-01-02", req.Mulai)
	b, e2 := time.Parse("2006-01-02", req.Selesai)
	switch {
	case !known:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Jenis cuti tidak dikenal"})
		return
	case e1 != nil || e2 != nil || b.Before(a) || b.Sub(a) > 366*24*time.Hour:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Tanggal cuti tidak valid"})
		return
	case len([]rune(req.Alasan)) < 5 || len(req.Alasan) > 1000:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Alasan cuti wajib diisi (5–1000 karakter)"})
		return
	}
	jumlah := int(b.Sub(a).Hours()/24) + 1
	id := uuid.New().String()
	tx, err := db.DBa.Begin()
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menyimpan"})
		return
	}
	defer tx.Rollback()
	langsung := s.Role == asn.EcarakdekSuperAdmin || s.Role == asn.EcarakdekAdminOPD // admin: langsung disetujui
	if langsung {
		_, err = tx.Exec(`INSERT INTO cuti_master (id, id_pegawai, jenis_cuti, tanggal_mulai, tanggal_selesai, jumlah_hari, alasan, status, diproses_oleh, diproses_at)
			VALUES (?,?,?,?,?,?,?,'disetujui',?,NOW())`, id, req.PnsID, req.Jenis, req.Mulai, req.Selesai, jumlah, req.Alasan, ecarakdekOleh(s))
	} else {
		_, err = tx.Exec(`INSERT INTO cuti_master (id, id_pegawai, jenis_cuti, tanggal_mulai, tanggal_selesai, jumlah_hari, alasan, status)
			VALUES (?,?,?,?,?,?,?,'diajukan')`, id, req.PnsID, req.Jenis, req.Mulai, req.Selesai, jumlah, req.Alasan)
	}
	for d := a; langsung && err == nil && !d.After(b); d = d.AddDate(0, 0, 1) { // sama dengan lain.tulisAbsenLainCuti
		_, err = tx.Exec(`INSERT INTO absen_lain (id_pegawai, tanggal, jenis, ref_type, ref_uuid, keterangan) VALUES (?,?,?,?,?,?)`,
			req.PnsID, d.Format("2006-01-02"), "CUTI", "CUTI", id, req.Alasan)
	}
	if err == nil {
		err = tx.Commit()
	}
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Cuti gagal disimpan"})
		return
	}
	pesan := "Cuti disimpan dan langsung disetujui"
	if !langsung {
		pesan = "Cuti disimpan; menunggu persetujuan"
	}
	c.JSON(http.StatusCreated, gin.H{"success": true, "id": id, "jumlah_hari": jumlah, "status": map[bool]string{true: "disetujui", false: "diajukan"}[langsung], "message": pesan})
}

// POST /absen/dinas {pns_ids: [...], jenis: DALAM|LUAR, tempat, tanggal_awal, tanggal_akhir, keterangan, tujuan_*}
func ecarakdekDinasInput(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	var req struct {
		dinasPengajuan
		PnsIDs []string `json:"pns_ids"`
	}
	if c.ShouldBindJSON(&req) != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Data tidak valid"})
		return
	}
	if err := req.validate(); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Dinas: " + err.Error()})
		return
	}
	peserta, ada := []string{}, map[string]bool{}
	for _, p := range req.PnsIDs {
		if p = strings.TrimSpace(p); p != "" && !ada[p] {
			ada[p] = true
			peserta = append(peserta, p)
		}
	}
	if len(peserta) == 0 || len(peserta) > 200 {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Pilih 1–200 peserta dinas"})
		return
	}
	boleh := asn.EcarakdekSaringPns(s, peserta)
	for _, p := range peserta {
		if !boleh[p] {
			c.JSON(http.StatusForbidden, gin.H{"error": "Ada peserta di luar cakupan akun"})
			return
		}
	}
	id := uuid.New().String()
	tx, err := db.DBa.Begin()
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Gagal menyimpan"})
		return
	}
	defer tx.Rollback()
	langsung := s.Role == asn.EcarakdekSuperAdmin || s.Role == asn.EcarakdekAdminOPD // admin: langsung disetujui
	if langsung {
		_, err = tx.Exec(`INSERT INTO dinas_master (id,jenis,tempat,tanggal_awal,tanggal_akhir,keterangan,status,diajukan_oleh,diajukan_pada,diproses_oleh,diproses_pada,
			tujuan_kabkota,tujuan_kecamatan,tujuan_desa) VALUES (?,?,?,?,?,?,'disetujui',?,NOW(),?,NOW(),?,?,?)`,
			id, req.Jenis, req.Tempat, req.TglAwal, req.TglAkhir, req.Keterangan, ecarakdekOleh(s), ecarakdekOleh(s),
			nullable(req.TujuanKabKota), nullable(req.TujuanKec), nullable(req.TujuanDesa))
	} else {
		_, err = tx.Exec(`INSERT INTO dinas_master (id,jenis,tempat,tanggal_awal,tanggal_akhir,keterangan,status,diajukan_oleh,diajukan_pada,
			tujuan_kabkota,tujuan_kecamatan,tujuan_desa) VALUES (?,?,?,?,?,?,'diajukan',?,NOW(),?,?,?)`,
			id, req.Jenis, req.Tempat, req.TglAwal, req.TglAkhir, req.Keterangan, ecarakdekOleh(s),
			nullable(req.TujuanKabKota), nullable(req.TujuanKec), nullable(req.TujuanDesa))
	}
	start, _ := time.Parse("2006-01-02", req.TglAwal)
	end, _ := time.Parse("2006-01-02", req.TglAkhir)
	for _, p := range peserta { // sama dengan dinasSetStatus (disetujui)
		if err == nil {
			_, err = tx.Exec(`INSERT INTO dinas_peserta (id_dinas, id_pegawai) VALUES (?,?)`, id, p)
		}
		for d := start; langsung && err == nil && !d.After(end); d = d.AddDate(0, 0, 1) {
			_, err = tx.Exec(`INSERT INTO absen_lain (id_pegawai,tanggal,jenis,ref_type,ref_uuid,keterangan) VALUES (?,?,?,?,?,?)`,
				p, d.Format("2006-01-02"), req.Jenis, "DINAS", id, req.Keterangan)
		}
	}
	if err == nil {
		err = tx.Commit()
	}
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Dinas gagal disimpan"})
		return
	}
	pesan := "Dinas disimpan dan langsung disetujui"
	if !langsung {
		pesan = "Dinas disimpan; menunggu persetujuan"
	}
	c.JSON(http.StatusCreated, gin.H{"success": true, "id": id, "status": map[bool]string{true: "disetujui", false: "diajukan"}[langsung], "message": pesan})
}

/* ======================================================
   DOKUMEN PDF CUTI & DINAS (file di server e-Carakdek, data di dokumen_repo SIMPEG)
   PHP e-Carakdek menyimpan file lalu mendaftarkannya di sini; APEL membukanya lewat
   /download-dok?id= (repo.ecarakdekDokAmbil mengambil dari e-Carakdek).
====================================================== */

// ecarakdekDokRefBoleh: cuti/dinas :ref milik akun (guru: dirinya; admin: semua pegawai dalam cakupan).
func ecarakdekDokRefBoleh(s asn.EcarakdekScope, kategori, ref string) bool {
	peg := []string{}
	switch kategori {
	case "cuti":
		var p string
		if db.DBa.QueryRow(`SELECT id_pegawai FROM cuti_master WHERE id = ?`, ref).Scan(&p) == nil {
			peg = append(peg, p)
		}
	case "dinas":
		if rows, err := db.DBa.Query(`SELECT id_pegawai FROM dinas_peserta WHERE id_dinas = ?`, ref); err == nil {
			for rows.Next() {
				var p string
				if rows.Scan(&p) == nil {
					peg = append(peg, p)
				}
			}
			rows.Close()
		}
	}
	if len(peg) == 0 {
		return false
	}
	if s.Role == asn.EcarakdekPegawai {
		for _, p := range peg {
			if p == s.PnsID {
				return true
			}
		}
		return false
	}
	boleh := asn.EcarakdekSaringPns(s, peg)
	for _, p := range peg {
		if !boleh[p] {
			return false
		}
	}
	return true
}

func ecarakdekDokKategori(k string) (string, bool) {
	k = strings.ToLower(strings.TrimSpace(k))
	return k, k == "cuti" || k == "dinas"
}

// GET /absen/dokumen?kategori=cuti|dinas&ref_id= - dokumen pendukung SAAT INI (terbaru; unggahan e-Carakdek maupun APEL).
// Sama dengan APEL ("Dokumen saat ini"): satu dokumen per cuti/dinas, unggahan baru menggantikan yang lama.
func ecarakdekDokList(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	kategori, ok := ecarakdekDokKategori(c.Query("kategori"))
	ref := strings.TrimSpace(c.Query("ref_id"))
	if !ok || ref == "" || !ecarakdekDokRefBoleh(s, kategori, ref) {
		c.JSON(http.StatusForbidden, gin.H{"error": "Data tidak ditemukan atau di luar cakupan akun"})
		return
	}
	rows, err := db.DB.Query(`SELECT id, nama_file_asli, file_path, COALESCE(ukuran,0), DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') FROM dokumen_repo
		WHERE ref_id = ? AND kategori = ? AND is_aktif = 1 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1`, ref, kategori)
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Daftar dokumen tidak dapat dibaca"})
		return
	}
	defer rows.Close()
	out := []gin.H{}
	for rows.Next() {
		var id, nama, fp, pada string
		var ukuran int64
		if rows.Scan(&id, &nama, &fp, &ukuran, &pada) == nil {
			lokal := strings.HasPrefix(fp, repo.EcarakdekDokPrefix)
			d := gin.H{"id": id, "nama": nama, "ukuran": ukuran, "pada": pada, "sumber": map[bool]string{true: "ecarakdek", false: "apel"}[lokal]}
			if lokal {
				d["file"] = strings.TrimPrefix(fp, repo.EcarakdekDokPrefix)
			}
			out = append(out, d)
		}
	}
	c.JSON(http.StatusOK, gin.H{"data": out})
}

// POST /absen/dokumen {kategori, ref_id, nama_file_asli, file, ukuran} - daftarkan PDF yang sudah disimpan PHP e-Carakdek.
func ecarakdekDokDaftar(c *gin.Context) {
	s, _ := asn.EcarakdekScopeOf(c)
	var r struct {
		Kategori string `json:"kategori"`
		Ref      string `json:"ref_id"`
		Nama     string `json:"nama_file_asli"`
		File     string `json:"file"`
		Ukuran   int64  `json:"ukuran"`
	}
	if c.ShouldBindJSON(&r) != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "Data tidak valid"})
		return
	}
	kategori, ok := ecarakdekDokKategori(r.Kategori)
	r.Ref, r.Nama = strings.TrimSpace(r.Ref), strings.TrimSpace(r.Nama)
	switch {
	case !ok || !repo.EcarakdekDokPola.MatchString(r.File) || !strings.HasPrefix(r.File, kategori+"/"):
		c.JSON(http.StatusBadRequest, gin.H{"error": "Berkas tidak valid"})
		return
	case r.Ukuran <= 0 || r.Ukuran > 5<<20:
		c.JSON(http.StatusBadRequest, gin.H{"error": "Ukuran berkas tidak valid"})
		return
	case r.Ref == "" || !ecarakdekDokRefBoleh(s, kategori, r.Ref):
		c.JSON(http.StatusForbidden, gin.H{"error": "Data tidak ditemukan atau di luar cakupan akun"})
		return
	}
	if r.Nama == "" || len(r.Nama) > 200 {
		r.Nama = kategori + ".pdf"
	}
	id := uuid.New().String()
	if _, err := db.DB.Exec(`INSERT INTO dokumen_repo (id, ref_id, kategori, tipe, nama_file_asli, nama_file_simpan, file_path, mime_type, ukuran, is_aktif)
		VALUES (?,?,?,'usul',?,?,?,'application/pdf',?,1)`, id, r.Ref, kategori, r.Nama, path.Base(r.File), repo.EcarakdekDokPrefix+r.File, r.Ukuran); err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "Dokumen gagal dicatat"})
		return
	}
	// mengganti: dokumen lama ref ini DIHAPUS (baris & file, di server e-Carakdek maupun repository APEL)
	repo.HapusDokumenLama(r.Ref, kategori, id)
	c.JSON(http.StatusCreated, gin.H{"success": true, "id": id, "message": "Dokumen PDF tersimpan"})
}

// hariKerjaBuild: penanda versi pemeriksaan hari kerja sekolah (cek apakah build terbaru sudah berjalan).
const hariKerjaBuild = "hk-sekolah-2026-10-24c"

// namaUnitSekolah: nama unit di SIMPEG (tbl_unit.nmunit) untuk halaman cek.
func namaUnitSekolah(unit string) string {
	var nama string
	if db.DB != nil && unit != "" {
		_ = db.DB.QueryRow(`SELECT COALESCE(nmunit,'') FROM tbl_unit WHERE id_unit = ?`, unit).Scan(&nama)
	}
	return strings.TrimSpace(nama)
}

// cekHariKerjaSekolah: GET /portal/cek-hari-kerja (portal guru, data diri sendiri) - rantai penentu hari kerja
// guru/tendik: unit absen, jenjang, pola, jadwal jenjang hari ini, serta unit & pola yang dipakai rekap.
func cekHariKerjaSekolah(c *gin.Context, nip, unit string) {
	now := simulasiData.now()
	hari := namaHari[now.Weekday()]
	all := polaSemua()
	polaInfo := func(codes []string) []gin.H {
		out := []gin.H{}
		for _, k := range codes {
			p := all[k]
			out = append(out, gin.H{"kode": k, "hari_kerja": p.HariKerja, "reguler": p.reguler()})
		}
		return out
	}
	jadwalJenjang := func(u string) []string {
		out := []string{}
		j := jenjangUnit(u)
		for k := range jadwalSekolahSemua() {
			if j != "" && strings.HasPrefix(k, j+"|") {
				out = append(out, k)
			}
		}
		sort.Strings(out)
		return out
	}
	absen := gin.H{"unit": unit, "unit_nama": namaUnitSekolah(unit), "jenjang": jenjangUnit(unit),
		"hari_kerja_jenjang": hariKerjaJenjang(jenjangUnit(unit)), "jadwal_jenjang": jadwalJenjang(unit)}
	if codes, err := polaUnitBerjenjang(unit); err == nil {
		absen["pola"] = polaInfo(codes)
		for _, k := range codes {
			if p := all[k]; p.reguler() {
				ctx := absenCtx{nip: nip, unit: unit, hari: hari}
				jm, e := ctx.jadwalReguler(p.HariKerja, "masuk")
				absen["jadwal_hari_ini"] = gin.H{"hari": hari, "hari_kerja": p.HariKerja, "ada": jm.Found, "buka": jm.Begin, "tutup": jm.End, "galat": e != nil}
				break
			}
		}
	} else {
		absen["pola_galat"] = err.Error()
	}
	rekap := gin.H{}
	if list, err := rekapData.pegawai("", nip); err == nil && len(list) > 0 {
		p := list[0]
		rekap["unit"], rekap["subunit"], rekap["unit_nama"] = p.Unit, p.SubUnit, namaUnitSekolah(p.Unit)
		rekap["jenjang"] = jenjangUnit(p.Unit)
		if codes, err := polaUnitBerjenjang(p.Unit); err == nil {
			rekap["pola"] = polaInfo(codes)
		}
		if k := konteksRekapSekarang(); k != nil {
			rekap["pola_konteks"] = polaInfo(k.polaBerjenjang(p.Unit))
		}
	} else if err != nil {
		rekap["galat"] = err.Error()
	}
	c.JSON(http.StatusOK, gin.H{"build": hariKerjaBuild, "nip": nip, "hari": hari, "modul_ecarakdek": ecarakdekNipModul(nip),
		"ada_jadwal_sekolah": adaJadwalSekolah(), "absen": absen, "rekap": rekap})
}
