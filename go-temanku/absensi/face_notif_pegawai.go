package absensi

// Notifikasi pegawai (TEMANKU web): GET /notifikasi, GET /notifikasi/belum-dibaca, PUT /notifikasi/baca.
// Identitas dari token SSO (parameter ?nip= dari web diabaikan). Isi 60 hari terakhir:
// cuti/dinas/claim disetujui|ditolak, absen tidak sah, absen dikoreksi admin, pengumuman APEL yang tayang.

import (
	"net/http"
	"sort"
	"strconv"
	"strings"

	"temanku/db"

	"github.com/gin-gonic/gin"
)

func notifPegawai(nip, pnsID string) []gin.H {
	out := []gin.H{}
	tambah := func(jenis, judul, isi, waktu string) {
		if waktu != "" {
			out = append(out, gin.H{"jenis": jenis, "judul": judul, "isi": strings.TrimSpace(isi), "created_at": waktu})
		}
	}
	keputusan := func(status string) (string, string) {
		if status == "disetujui" {
			return "sukses", "disetujui"
		}
		return "bahaya", "ditolak"
	}
	catatan := func(c string) string {
		if c = strings.TrimSpace(c); c != "" {
			return " Catatan: " + c
		}
		return ""
	}
	if pnsID != "" {
		kueriCuti := func(kolom string) string {
			return `SELECT COALESCE(jenis_cuti,''), DATE_FORMAT(tanggal_mulai,'%d-%m-%Y'), DATE_FORMAT(tanggal_selesai,'%d-%m-%Y'), status,
			COALESCE(catatan_admin,''), DATE_FORMAT(` + kolom + `,'%Y-%m-%d %H:%i:%s') FROM cuti_master
			WHERE id_pegawai=? AND status IN ('disetujui','ditolak') AND ` + kolom + ` >= NOW() - INTERVAL 60 DAY`
		}
		rows, err := db.DBa.Query(kueriCuti("diproses_at"), pnsID) // lain/cuti.go menulis diproses_at
		if err != nil {
			rows, err = db.DBa.Query(kueriCuti("diproses_pada"), pnsID) // skema lama
		}
		if err == nil {
			for rows.Next() {
				var jenis, mulai, selesai, status, cat, waktu string
				if rows.Scan(&jenis, &mulai, &selesai, &status, &cat, &waktu) == nil {
					j, kata := keputusan(status)
					tambah(j, "Cuti "+kata, strings.TrimSpace("Pengajuan cuti "+jenis+" "+mulai+" s.d. "+selesai+" "+kata+".")+catatan(cat), waktu)
				}
			}
			rows.Close()
		}
		// Usulan perubahan riwayat ASN yang sudah diverifikasi admin SIMPEG (face_notif_usulan.go).
		for _, n := range notifUsulanRiwayat(pnsID) {
			tambah(n.jenis, n.judul, n.isi, n.waktu)
		}
	}
	if rows, err := db.DBa.Query(`SELECT status, COALESCE(catatan_admin,''), DATE_FORMAT(diproses_pada,'%Y-%m-%d %H:%i:%s') FROM dinas_master
		WHERE diajukan_oleh=? AND status IN ('disetujui','ditolak') AND diproses_pada >= NOW() - INTERVAL 60 DAY`, nip); err == nil {
		for rows.Next() {
			var status, cat, waktu string
			if rows.Scan(&status, &cat, &waktu) == nil {
				j, kata := keputusan(status)
				tambah(j, "Perjalanan dinas "+kata, "Pengajuan perjalanan dinas Anda "+kata+"."+catatan(cat), waktu)
			}
		}
		rows.Close()
	}
	if rows, err := db.DBa.Query(`SELECT DATE_FORMAT(tanggal,'%d-%m-%Y'), status, COALESCE(catatan_admin,''), DATE_FORMAT(diproses_pada,'%Y-%m-%d %H:%i:%s') FROM claim_presensi
		WHERE nip=? AND status IN ('disetujui','ditolak') AND diproses_pada >= NOW() - INTERVAL 60 DAY`, nip); err == nil {
		for rows.Next() {
			var tgl, status, cat, waktu string
			if rows.Scan(&tgl, &status, &cat, &waktu) == nil {
				j, kata := keputusan(status)
				tambah(j, "Claim presensi "+kata, "Claim presensi tanggal "+tgl+" "+kata+"."+catatan(cat), waktu)
			}
		}
		rows.Close()
	}
	if rows, err := db.DBa.Query(`SELECT DATE_FORMAT(tanggal,'%d-%m-%Y'), alasan, DATE_FORMAT(pada,'%Y-%m-%d %H:%i:%s') FROM absen_tinjauan
		WHERE nip=? AND status='TIDAK_SAH' AND pada >= NOW() - INTERVAL 60 DAY`, nip); err == nil {
		for rows.Next() {
			var tgl, alasan, waktu string
			if rows.Scan(&tgl, &alasan, &waktu) == nil {
				tambah("bahaya", "Absen dinyatakan tidak sah", "Absen tanggal "+tgl+" tidak dihitung (TAK). Alasan: "+alasan, waktu)
			}
		}
		rows.Close()
	}
	if rows, err := db.DBa.Query(`SELECT DATE_FORMAT(tanggal,'%d-%m-%Y'), DATE_FORMAT(masuk,'%H:%i'), COALESCE(DATE_FORMAT(pulang,'%H:%i'),'-'), alasan,
		DATE_FORMAT(pada,'%Y-%m-%d %H:%i:%s') FROM absen_koreksi WHERE nip=? AND pada >= NOW() - INTERVAL 60 DAY`, nip); err == nil {
		for rows.Next() {
			var tgl, masuk, pulang, alasan, waktu string
			if rows.Scan(&tgl, &masuk, &pulang, &alasan, &waktu) == nil {
				tambah("info", "Absen dikoreksi admin", "Absen tanggal "+tgl+" menjadi "+masuk+"–"+pulang+". Alasan: "+alasan, waktu)
			}
		}
		rows.Close()
	}
	for _, p := range pengumumanUntuk(nip) {
		tambah("peringatan", p["judul"].(string), p["isi"].(string), p["tayang"].(string)) // waktu dari database (sejalan dengan penanda dibaca)
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i]["created_at"].(string) > out[j]["created_at"].(string) })
	return out
}

func notifTerakhirBaca(nip string) string {
	var t string
	_ = db.DBa.QueryRow(`SELECT DATE_FORMAT(terakhir,'%Y-%m-%d %H:%i:%s') FROM notifikasi_baca WHERE nip=?`, nip).Scan(&t)
	return t
}

func notifPegawaiList(c *gin.Context) {
	nip, pnsID, _, level, ok := portalAccount(c)
	if !ok {
		return
	}
	if level != 5 || nip == "" {
		c.JSON(http.StatusOK, gin.H{"data": []gin.H{}})
		return
	}
	limit, _ := strconv.Atoi(c.DefaultQuery("limit", "20"))
	if limit < 1 || limit > 100 {
		limit = 20
	}
	list := notifPegawai(nip, strings.TrimSpace(pnsID))
	baca := notifTerakhirBaca(nip)
	for _, n := range list {
		n["dibaca"] = baca != "" && n["created_at"].(string) <= baca
	}
	if len(list) > limit {
		list = list[:limit]
	}
	c.JSON(http.StatusOK, gin.H{"data": list})
}

func notifPegawaiBelum(c *gin.Context) {
	nip, pnsID, _, level, ok := portalAccount(c)
	if !ok {
		return
	}
	n := 0
	if level == 5 && nip != "" {
		baca := notifTerakhirBaca(nip)
		for _, x := range notifPegawai(nip, strings.TrimSpace(pnsID)) {
			if x["created_at"].(string) > baca {
				n++
			}
		}
	}
	c.JSON(http.StatusOK, gin.H{"data": gin.H{"jumlah": n}})
}

func notifPegawaiBaca(c *gin.Context) {
	nip, _, _, level, ok := portalAccount(c)
	if !ok {
		return
	}
	if level == 5 && nip != "" {
		_, _ = db.DBa.Exec(`INSERT INTO notifikasi_baca (nip, terakhir) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE terakhir=NOW()`, nip)
	}
	c.JSON(http.StatusOK, gin.H{"success": true})
}
