package absensi

// PORTAL GURU e-CARAKDEK (web /v2/guru) - tampilan & fungsi sama dengan TEMANKU web, untuk guru/tendik.
// Path dibuat SAMA dengan rute portal TEMANKU (/temanku/api/v1/portal/..., /notifikasi, /wilayah/...)
// sehingga halaman PHP TEMANKU dipakai ulang hanya dengan mengganti base URL ke /ecarakdek/api/v1.
//
// Dipasang pada grup web e-Carakdek (DualAuthMiddleware + asn.EcarakdekScopeMiddleware, lihat
// router/ecarakdek_web.router.go). Handler TEMANKU dipakai apa adanya lewat akun pengganti (portalAkun):
// portalAccount() mengembalikan NIP/PNS_ID dari cakupan e-Carakdek, bukan dari klaim modul TEMANKU,
// sehingga akun sekolah tidak ditolak "gunakan aplikasi eCarakdek". Setiap rute hanya untuk guru/tendik
// unit sekolah yang absen sendiri (aturan sama dengan ecarakdekDiriSendiri).

import (
	"net/http"
	"strings"

	"temanku/asn"

	"github.com/gin-gonic/gin"
)

// ecarakdekPortalGuru: hanya guru/tendik unit sekolah; akun pengganti diisi level ASN (5) dan unit APEL pegawai.
func ecarakdekPortalGuru() gin.HandlerFunc {
	return func(c *gin.Context) {
		s, ok := asn.EcarakdekScopeOf(c)
		if !ok || !nipPola.MatchString(s.NIP) || s.PnsID == "" || !ecarakdekBoleh([]string{s.NIP})[s.NIP] {
			ecarakdekTolak(c, http.StatusForbidden, "Portal guru e-Carakdek hanya untuk guru/tendik unit sekolah")
			return
		}
		c.Set(portalAkunKey, portalAkun{nip: s.NIP, pnsID: s.PnsID, unit: unitByNip(s.NIP), level: 5})
		c.Next()
	}
}

// RegisterEcarakdekPortal mendaftarkan rute portal guru pada grup web e-Carakdek.
func RegisterEcarakdekPortal(web *gin.RouterGroup) {
	g := web.Group("")
	g.Use(faceRemoteGuard("ECARAKDEK_WEB_ALLOWED_IPS"), ecarakdekPortalGuru())

	akun := func(h func(c *gin.Context, nip, pnsID, unit string)) gin.HandlerFunc {
		return func(c *gin.Context) {
			nip, pnsID, unit, _, ok := portalAccount(c)
			if ok {
				h(c, nip, strings.TrimSpace(pnsID), strings.TrimSpace(unit))
			}
		}
	}

	// Profil & presensi (Beranda, Absensi, Rekap)
	g.GET("/portal/profile", PortalProfile)
	g.POST("/portal/profile/refresh", PortalRefreshProfile)
	g.GET("/portal/attendance", PortalAttendance)
	g.POST("/portal/attendance/:action", PortalCheckAttendance)
	g.GET("/portal/akses", func(c *gin.Context) { c.JSON(http.StatusOK, gin.H{"success": true}) })
	g.GET("/portal/absen/metode", akun(func(c *gin.Context, _, _, unit string) {
		c.JSON(http.StatusOK, gin.H{"metode": metodeAbsen(unit)})
	}))

	// Wajah: rekam, verifikasi, absen sungguhan & simulasi (engine & aturan sama dengan TEMANKU)
	for _, op := range faceV2Operations {
		operation := op
		g.POST("/portal/face/v2/"+operation, akun(func(c *gin.Context, nip, _, unit string) {
			if wajahBelumAktif(c, unit, operation) {
				return
			}
			faceV2Forward(c, nip, portalSessionMaterial(c), operation, "web")
		}))
	}
	g.POST("/portal/face/v2/photo", akun(func(c *gin.Context, nip, _, _ string) { facePhotoPreview(c, nip) }))
	g.POST("/portal/face/v2/absen/:action", akun(func(c *gin.Context, nip, pnsID, unit string) {
		faceAbsen(c, nip, pnsID, unit, portalSessionMaterial(c), "web")
	}))
	g.POST("/portal/face/v2/simulasi/:action", akun(func(c *gin.Context, nip, _, unit string) {
		if wajahBelumAktif(c, unit, "simulasi") {
			return
		}
		faceSimulasi(c, nip, unit, portalSessionMaterial(c), "web")
	}))

	// Claim presensi, cuti & dinas (disetujui admin sekolah di e-Carakdek / admin OPD di APEL)
	g.POST("/portal/claim", akun(func(c *gin.Context, nip, _, _ string) { ajukanClaim(c, nip) }))
	g.GET("/portal/claim", akun(func(c *gin.Context, nip, _, _ string) { claimSaya(c, nip) }))
	g.POST("/portal/cuti/pengajuan", akun(func(c *gin.Context, _, pnsID, _ string) { ajukanCuti(c, pnsID) }))
	g.GET("/portal/cuti/pengajuan", akun(func(c *gin.Context, _, pnsID, _ string) { cutiSaya(c, pnsID) }))
	g.POST("/portal/dinas/pengajuan", akun(func(c *gin.Context, nip, pnsID, _ string) { ajukanDinas(c, nip, pnsID) }))
	g.GET("/portal/dinas/pengajuan", akun(func(c *gin.Context, _, pnsID, _ string) { dinasSaya(c, pnsID) }))
	g.GET("/wilayah/kabkota", wilayahKabKota)
	g.GET("/wilayah/selayar", wilayahSelayar)

	// Notifikasi & pengumuman
	g.GET("/notifikasi", notifPegawaiList)
	g.GET("/notifikasi/belum-dibaca", notifPegawaiBelum)
	g.PUT("/notifikasi/baca", notifPegawaiBaca)
	g.GET("/portal/pengumuman", akun(func(c *gin.Context, nip, _, _ string) {
		c.JSON(http.StatusOK, gin.H{"data": pengumumanUntuk(nip)})
	}))
}
