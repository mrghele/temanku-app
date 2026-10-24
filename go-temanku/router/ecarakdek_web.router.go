package router

import (
	"temanku/absensi"
	"temanku/asn"
	"temanku/middleware"
	"temanku/pref"
	"temanku/repo"

	"github.com/gin-gonic/gin"
)

// RegisterEcarakdekWebRoutes mendaftarkan API untuk WEB e-Carakdek
// (ecarakdek.kepulauanselayarkab.go.id) - fungsi kepegawaian SAMA dengan
// SIMPEG (handler & database yang sama), tetapi:
//
//   - wajib dua token (token servis + token SSO user), seperti SIMPEG
//   - setiap request dibatasi ke ASN unit sekolah sesuai peran user
//     (lihat asn/ecarakdek_scope.go): super admin (NIP Dinas Pendidikan
//     di tabel ecarakdek_superadmin), admin sekolah, atau pegawai sendiri
//
// Satu prefix dengan rute mobile: /ecarakdek/api/v1. Middleware di bawah hanya berlaku
// untuk rute yang didaftarkan lewat grup `web` ini; rute mobile (/login, /face/v2/...,
// /mobile/...) tetap memakai token aplikasi. Rute lama tanpa pembatas yang path-nya sama
// (/agama, /upload-dok, /download-dok, /asn/pegawai-list, /asn/pegawai-table,
// /asn/data-utama/:id) sudah dihapus dari ecarakdek.router.go - path yang sama didaftarkan
// dua kali membuat Gin panic saat start. Path dibuat SAMA dengan /simpeg/api/v1 agar kode
// PHP SIMPEG bisa dipakai ulang hanya dengan mengganti base URL.
func RegisterEcarakdekWebRoutes(r *gin.Engine) {
	web := r.Group("/ecarakdek/api/v1")
	web.Use(middleware.DualAuthMiddleware(), asn.EcarakdekScopeMiddleware())

	// e-Carakdek TIDAK terhubung ke SIASN: sinkron/kirim/hapus SIASN, tambah pegawai dari BKN
	// dan verifikasi usulan hanya lewat /simpeg/api/v1 (BKPSDM). Super admin e-Carakdek setara
	// admin sekolah, hanya cakupannya semua sekolah - tidak ada rute khusus super admin di sini.
	admin := asn.EcarakdekRequireRole(asn.EcarakdekSuperAdmin, asn.EcarakdekAdminOPD)
	byPath := asn.EcarakdekGuardPath("id")
	byBody := asn.EcarakdekGuardBody("pns_id")
	byUsulan := asn.EcarakdekGuardUsulan()
	listScope := asn.EcarakdekForceListScope()

	// PROFIL & PERAN USER LOGIN
	web.GET("/asn/me", asn.GetEcarakdekMe)

	// REFERENSI (baca saja)
	web.GET("/agama", pref.GetAllAgamas)
	web.GET("/unit", pref.GetAllUnits)
	web.GET("/wilayah", pref.GetAllWilayahs)
	web.GET("/asn/unit-pilihan", asn.GetUnitPilihan)
	web.GET("/asn/ref-pendidikan", asn.GetRefPendidikan)
	web.GET("/asn/ref-jurusan", asn.GetRefJurusan)
	web.GET("/asn/ref-penghargaan", asn.GetRefPenghargaan)
	web.GET("/asn/ref-hukdis", asn.GetRefHukdis)
	web.GET("/asn/ref-jabatan", asn.GetRefJabatan)
	web.GET("/asn/ref-diklat", asn.GetRefDiklat)
	web.GET("/asn/ref-diklatpim", asn.GetRefDiklatPim)

	// DOKUMEN (akses per pegawai dicek di aplikasi web sebelum memanggil)
	web.POST("/upload-dok", repo.UploadDokumen)
	web.GET("/download-dok", repo.DownloadDokumen)

	// DAFTAR PEGAWAI (filter sekolah/unit dipaksa dari scope)
	web.GET("/asn/pegawai-list", listScope, asn.GetPegawaiList)
	web.GET("/asn/pegawai-list/kedudukan", listScope, asn.GetPegawaiListKedudukan)
	web.GET("/asn/pegawai-table", listScope, asn.GetPegawaiTable)

	// DATA UTAMA
	web.GET("/asn/data-utama/:id", byPath, asn.GetPegawaiByID)
	web.POST("/asn/data-utama/foto", admin, asn.EcarakdekGuardBody("id"), asn.UpdateFotoPegawai)
	web.GET("/asn/data-utama/:id/identitas", byPath, asn.GetIdentitasPegawai)
	web.PATCH("/asn/data-utama/:id/identitas", admin, byPath, asn.EcarakdekGuardUnitTujuan(), asn.PatchIdentitasPegawai)

	// RIWAYAT: hanya dibaca (perubahan lewat usulan; sinkron & hapus dari SIASN dilakukan di SIMPEG)
	for path, get := range map[string]gin.HandlerFunc{
		"rw-pendidikan":  asn.GetAllRWPendidikanByPNS,
		"rw-golongan":    asn.GetRWGolonganByPNS,
		"rw-penghargaan": asn.GetRWPenghargaanByPNS,
		"rw-hukdis":      asn.GetRWHukdisByPNS,
		"rw-kinerja":     asn.GetRWKinerjaByPNS,
		"rw-pak":         asn.GetRWPakByPNS,
		"rw-cpns":        asn.GetRWCpnsByPNS,
		"rw-jabatan":     asn.GetRWJabatanByPNS,
		"rw-kursus":      asn.GetRWKursusByPNS,
		"rw-diklatpim":   asn.GetRWDiklatPimByPNS,
		"rw-pasangan":    asn.GetRWPasanganByPNS,
	} {
		web.GET("/asn/"+path+"/pns/:id", byPath, get)
	}

// SINKRON DARI SIASN: diteruskan ke gerbang sync SIMPEG (e-Carakdek sendiri tidak terhubung SIASN)
	web.POST("/asn/sinkron/:jenis", asn.EcarakdekGuardSinkron(), asn.EcarakdekGuardBody("id"), asn.PostEcarakdekSinkron)

	// USULAN RIWAYAT (tambah/ubah/hapus diajukan di sini, diverifikasi BKPSDM di SIMPEG)
	web.GET("/asn/usulan-riwayat", asn.EcarakdekGuardQuery("pns_id"), asn.GetUsulanRiwayat)
	web.POST("/asn/usulan-riwayat", byBody, asn.PostUsulanRiwayat)
	web.GET("/asn/usulan-riwayat/:id", byUsulan, asn.GetUsulanRiwayatByID)
	web.PUT("/asn/usulan-riwayat/:id", byUsulan, asn.PutUsulanRiwayat)
	web.DELETE("/asn/usulan-riwayat/:id", byUsulan, asn.DeleteUsulanRiwayat)

	// "USULAN SAYA" (daftar usulan dalam cakupan; status verifikasi diisi BKPSDM di SIMPEG)
	web.GET("/asn/usulan-verifikasi", admin, asn.GetUsulanVerifikasi)

	// ABSENSI: presensi guru/tendik (disimpan di carakdek_web), rekap, rekam wajah,
	// cuti & dinas luar, titik koordinat sekolah, pengajuan lokasi tambahan
	absensi.RegisterEcarakdekWebAbsen(web)

	// PORTAL GURU (/v2/guru): tampilan & fungsi TEMANKU untuk guru/tendik - beranda, absen wajah, rekap,
	// claim, cuti, dinas, notifikasi (lihat absensi/ecarakdek_portal.go)
	absensi.RegisterEcarakdekPortal(web)
}
