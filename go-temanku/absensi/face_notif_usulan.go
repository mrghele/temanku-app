package absensi

// Notifikasi usulan riwayat ASN untuk pegawai (TEMANKU web): usulan tambah/ubah/hapus riwayat yang diajukan
// dari TEMANKU atau SIMPEG lalu diverifikasi admin SIMPEG (disetujui / ditolak / dikembalikan untuk diperbaiki).
// Sumber: tabel usulan_riwayat di database SIMPEG (asn/usulan.go), 60 hari terakhir seperti notifikasi lain.

import (
	"strings"

	"temanku/db"
)

type notifUsulan struct {
	jenis, judul, isi, waktu string
}

var labelJenisUsulan = map[string]string{
	"pendidikan":  "riwayat pendidikan",
	"golongan":    "riwayat pangkat/golongan",
	"penghargaan": "riwayat penghargaan",
	"hukdis":      "riwayat hukuman disiplin",
	"kinerja":     "riwayat kinerja (SKP)",
	"pak":         "riwayat angka kredit",
	"cpns":        "data CPNS/PNS",
	"jabatan":     "riwayat jabatan",
	"tugas":       "riwayat tugas tambahan",
	"kursus":      "riwayat diklat/kursus",
	"diklatpim":   "riwayat diklat struktural",
	"pasangan":    "data pasangan",
}

var labelAksiUsulan = map[string]string{"tambah": "penambahan", "ubah": "perubahan", "hapus": "penghapusan"}

func notifUsulanRiwayat(pnsID string) []notifUsulan {
	out := []notifUsulan{}
	rows, err := db.DB.Query(`SELECT jenis, aksi, status, CAST(COALESCE(catatan,'') AS CHAR), DATE_FORMAT(diverifikasi_at,'%Y-%m-%d %H:%i:%s')
		FROM usulan_riwayat WHERE pns_id=? AND status IN ('disetujui','ditolak','dikembalikan')
		AND diverifikasi_at >= NOW() - INTERVAL 60 DAY`, pnsID)
	if err != nil {
		return out
	}
	defer rows.Close()
	for rows.Next() {
		var jenis, aksi, status, cat, waktu string
		if rows.Scan(&jenis, &aksi, &status, &cat, &waktu) != nil || waktu == "" {
			continue
		}
		obj := labelJenisUsulan[jenis]
		if obj == "" {
			obj = "riwayat " + jenis
		}
		usul := "Usulan " + labelAksiUsulan[aksi] + " " + obj
		if labelAksiUsulan[aksi] == "" {
			usul = "Usulan " + obj
		}
		if cat = strings.TrimSpace(cat); cat != "" {
			cat = " Catatan: " + cat
		}
		switch status {
		case "disetujui":
			out = append(out, notifUsulan{"sukses", "Usulan riwayat disetujui", usul + " Anda disetujui dan diteruskan ke SIASN BKN." + cat, waktu})
		case "ditolak":
			out = append(out, notifUsulan{"bahaya", "Usulan riwayat ditolak", usul + " Anda ditolak." + cat, waktu})
		default:
			out = append(out, notifUsulan{"info", "Usulan riwayat perlu diperbaiki", usul + " Anda dikembalikan untuk diperbaiki. Buka menu Riwayat > Usulan untuk memperbaiki dan mengajukan ulang." + cat, waktu})
		}
	}
	return out
}
