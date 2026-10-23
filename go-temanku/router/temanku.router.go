package router
import (
	"temanku/db"
	"temanku/pref"
	"temanku/asn"
	"temanku/lain"
	"temanku/repo"
	"temanku/middleware"
	"temanku/absensi"
	"github.com/gin-gonic/gin"
	_ "temanku/docs/temanku"

	ginSwagger "github.com/swaggo/gin-swagger"
	swaggerFiles "github.com/swaggo/files"
)
// @title Sistem Informasi Layanan Kepegawaian Terpadu
// @version 1.0
// @description API utama untuk layanan TEMANKU
// @contact.name Admin TEMANKU
// @BasePath /temanku/api/v1
// @schemes http

// @securityDefinitions.apikey ApiToken
// @in header
// @name Authorization
// @description Masukkan token API internal (format: Bearer <token>)

// @securityDefinitions.apikey SsoToken
// @in header
// @name Auth
// @description Masukkan token dari SSO (Keycloak)
func RegisterTemankuRoutes(r *gin.Engine) {
	db.Init()

	api := r.Group("/temanku/api/v1")
	absensi.RegisterFaceV2Routes(api)
	{
		api.GET("/", func(c *gin.Context) {
			c.JSON(200, gin.H{"mode": "temanku"})
		})

		// LOGIN MOBILE - TIDAK BOLEH untuk pegawai unit sekolah biasa (lihat pref.IsModuleAllowed)
		api.POST("/login", absensi.LoginTemanku)   // <-- BARU
				// ================= ABSENSI (device, lokasi, wajah, consent) =================
		// Semua butuh token hasil /login di atas (Authorization: Bearer <token>)
		absensiAuth := api.Group("/")
		absensiAuth.Use(middleware.AuthMiddleware())
		{
			// DEVICE BINDING
			absensiAuth.POST("/auth/verify-device", absensi.VerifyDevice)
			absensiAuth.POST("/auth/request-device-reset-otp", absensi.RequestDeviceResetOtp)
			absensiAuth.POST("/auth/confirm-device-reset", absensi.ConfirmDeviceReset)
			absensiAuth.POST("/devices/register", absensi.RegisterOnboarding)
			absensiAuth.GET("/devices/onboarding-status", absensi.OnboardingStatus)
			absensiAuth.POST("/devices/fcm-token", absensi.UpdateFcmToken)

			// LOKASI
			absensiAuth.GET("/schools/location", absensi.SchoolLocation)
			absensiAuth.POST("/location/report", absensi.ReportLocation)
			absensiAuth.GET("/holidays", absensi.Holidays)

			// CONSENT BIOMETRIK
			absensiAuth.GET("/consent/biometric-status", absensi.BiometricConsentStatus)
			absensiAuth.POST("/consent/biometric", absensi.RecordBiometricConsent)

			// WAJAH & ABSENSI
			absensiAuth.POST("/face/enroll", absensi.LegacyFaceDisabled)
			absensiAuth.GET("/face/enrollment-status", absensi.LegacyFaceDisabled)
			absensiAuth.POST("/liveness/request-challenge", absensi.LegacyFaceDisabled)
			absensiAuth.POST("/attendance/face-checkin", absensi.LegacyFaceDisabled)
			absensiAuth.GET("/attendance/today-status", absensi.TodayAttendanceStatus)
			absensiAuth.POST("/attendance/manual-request", absensi.ManualAttendanceRequestHandler)

			// COMMAND CENTER (dashboard admin dinas)
			absensiAuth.GET("/command-center/teachers", absensi.CommandCenterTeachers)
			absensiAuth.GET("/command-center/schools", absensi.CommandCenterSchools)
		}

		// Portal web: identitas SSO dan pembacaan presensi dari APEL.
		api.GET("/portal/profile", middleware.DualAuthMiddleware(), absensi.PortalProfile)
		api.GET("/portal/attendance", middleware.DualAuthMiddleware(), absensi.PortalAttendance)
		api.POST("/portal/profile/refresh", middleware.DualAuthMiddleware(), absensi.PortalRefreshProfile)
		api.POST("/portal/attendance/:action", middleware.DualAuthMiddleware(), absensi.PortalCheckAttendance)

		// USER
		api.GET("/useradmin", middleware.AuthMiddleware(), pref.GetUserByUsernameForModule(pref.ModulTemanku))

		// PENDIDIKAN
		api.GET("/pendidikan", middleware.DualAuthMiddleware(), pref.GetAllPendidikans)
		api.GET("/pendidikan/:id", middleware.DualAuthMiddleware(), pref.GetPendidikanByID)
		api.GET("/jurusan", middleware.DualAuthMiddleware(), pref.GetAllJurusans)
		api.GET("/jurusan/:id", middleware.DualAuthMiddleware(), pref.GetJurusanByID)
		
		// AGAMA
		api.GET("/agama", middleware.DualAuthMiddleware(), pref.GetAllAgamas)
		api.GET("/agama/:id", middleware.DualAuthMiddleware(), pref.GetAgamaByID)

		// UNIT
		api.GET("/unit", middleware.DualAuthMiddleware(), pref.GetAllUnits)
		api.GET("/unit/:id", middleware.DualAuthMiddleware(), pref.GetUnitByID)
		api.GET("/unit/atasan/:id", middleware.DualAuthMiddleware(), pref.GetUnitByAtasan)
		api.GET("/unit/utama", middleware.DualAuthMiddleware(), pref.GetUnitByUtama)
		api.GET("/unit/induk/:id", middleware.DualAuthMiddleware(), pref.GetUnitByInduk)

		// GOLONGAN
		api.GET("/golongan", middleware.DualAuthMiddleware(), pref.GetAllGolongans)
		api.GET("/golongan/:id", middleware.DualAuthMiddleware(), pref.GetGolonganByID)

		// ESELON
		api.GET("/eselon", middleware.DualAuthMiddleware(), pref.GetAllEselons)
		api.GET("/eselon/:id", middleware.DualAuthMiddleware(), pref.GetEselonByID)

		// KANREG
		api.GET("/kanreg", middleware.DualAuthMiddleware(), pref.GetAllKanregs)
		api.GET("/kanreg/:id", middleware.DualAuthMiddleware(), pref.GetKanregByID)

		// INSTANSI
		api.GET("/instansi", middleware.DualAuthMiddleware(), pref.GetAllInstansis)
		api.GET("/instansi/:id", middleware.DualAuthMiddleware(), pref.GetInstansiByID)

		// SATKER
		api.GET("/satker", middleware.DualAuthMiddleware(), pref.GetAllSatkers)
		api.GET("/satker/:id", middleware.DualAuthMiddleware(), pref.GetSatkerByID)

		// STRUKTURAL
		api.GET("/struktural", middleware.DualAuthMiddleware(), pref.GetAllStrukturals)
		api.GET("/struktural/:id", middleware.DualAuthMiddleware(), pref.GetStrukturalByID)

		// JF UMUM
		api.GET("/jfu", middleware.DualAuthMiddleware(), pref.GetAllJfus)
		api.GET("/jfu/:id", middleware.DualAuthMiddleware(), pref.GetJfuByID)
		
		// JFT
		api.GET("/jft", middleware.DualAuthMiddleware(), pref.GetAllJfts)
		api.GET("/jft/:id", middleware.DualAuthMiddleware(), pref.GetJftByID)

		//HUKDIS
		api.GET("/hukdis", middleware.DualAuthMiddleware(), pref.GetAllHukdiss)
		api.GET("/hukdis/:id", middleware.DualAuthMiddleware(), pref.GetHukdisByID)

		//KEDUDUKAN HUKUM
		api.GET("/kedudukan", middleware.DualAuthMiddleware(), pref.GetAllKedudukans)
		api.GET("/kedudukan/:id", middleware.DualAuthMiddleware(), pref.GetKedudukanByID)
		

		// Alasan
		api.GET("/alasan", middleware.DualAuthMiddleware(), pref.GetAllAlasans)
		api.GET("/alasan/:id", middleware.DualAuthMiddleware(), pref.GetAlasanByID)
		
		
		// DIKLAT
		api.GET("/diklat", middleware.DualAuthMiddleware(), pref.GetAllDiklats)
		api.GET("/diklat/:id", middleware.DualAuthMiddleware(), pref.GetDiklatByID)
		
		// KURSUS
		api.GET("/kursus", middleware.DualAuthMiddleware(), pref.GetAllKursuss)
		api.GET("/kursus/:id", middleware.DualAuthMiddleware(), pref.GetKursusByID)

		// DOKUMEN
		api.POST("/upload-dok", middleware.DualAuthMiddleware(), repo.UploadDokumen)
		api.GET("/download-dok", middleware.DualAuthMiddleware(), repo.DownloadDokumen)
		api.GET("/list-dok", middleware.DualAuthMiddleware(), repo.ListDokumenByRef)
		api.DELETE("/delete-dok", middleware.DualAuthMiddleware(), repo.DeleteDokumen)
		

		// LOKASI
		api.GET("/lokasi", middleware.DualAuthMiddleware(), pref.GetAllLokasis)
		api.GET("/lokasi/:id", middleware.DualAuthMiddleware(), pref.GetLokasiByID)
		api.POST("/lokasi", middleware.DualAuthMiddleware(), pref.PostLokasi)
		api.PUT("/lokasi/:id", middleware.DualAuthMiddleware(), pref.UpdateLokasi)
		api.DELETE("/lokasi/:id", middleware.DualAuthMiddleware(), pref.DeleteLokasiByID)

		// LOKASI UPACARA (tabel terpisah dari lokasi presensi)
		api.GET("/lokasi-upacara", middleware.DualAuthMiddleware(), pref.GetAllLokasiUpacara)
		api.GET("/lokasi-upacara/:id", middleware.DualAuthMiddleware(), pref.GetLokasiUpacaraByID)
		api.POST("/lokasi-upacara", middleware.DualAuthMiddleware(), pref.PostLokasiUpacara)
		api.PUT("/lokasi-upacara/:id", middleware.DualAuthMiddleware(), pref.UpdateLokasiUpacara)
		api.DELETE("/lokasi-upacara/:id", middleware.DualAuthMiddleware(), pref.DeleteLokasiUpacaraByID)

		// LIBUR
		api.GET("/libur", middleware.DualAuthMiddleware(), pref.GetAllLiburs)
		api.GET("/libur/:id", middleware.DualAuthMiddleware(), pref.GetLiburByID)
		api.POST("/libur", middleware.DualAuthMiddleware(), pref.PostLibur)
		api.PUT("/libur/:id", middleware.DualAuthMiddleware(), pref.UpdateLibur)
		api.DELETE("/libur/:id", middleware.DualAuthMiddleware(), pref.DeleteLiburByID)
		
		// SHIFT MASTER
		api.GET("/shift-master", middleware.DualAuthMiddleware(), pref.GetAllShiftMasters)
		api.GET("/shift-master/:id", middleware.DualAuthMiddleware(), pref.GetShiftMasterByID)
		api.POST("/shift-master", middleware.DualAuthMiddleware(), pref.PostShiftMaster)
		api.PUT("/shift-master/:id", middleware.DualAuthMiddleware(), pref.UpdateShiftMaster)
		api.DELETE("/shift-master/:id", middleware.DualAuthMiddleware(), pref.DeleteShiftMaster)

		// SHIFT DETAIL
		api.GET("/shift-detail", middleware.DualAuthMiddleware(), pref.GetAllShiftDetails)
		api.GET("/shift-detail/:id", middleware.DualAuthMiddleware(), pref.GetShiftDetailByID)
		api.GET("/shift-detail/shift/:shift_id", middleware.DualAuthMiddleware(), pref.GetAllShiftDetailsByShiftID)
		api.POST("/shift-detail", middleware.DualAuthMiddleware(), pref.PostShiftDetail)
		api.PUT("/shift-detail/:id", middleware.DualAuthMiddleware(), pref.UpdateShiftDetail)
		api.DELETE("/shift-detail/:id", middleware.DualAuthMiddleware(), pref.DeleteShiftDetailByID)

		// SHIFT KHUSUS
		api.GET("/shift-khusus", middleware.DualAuthMiddleware(), pref.GetAllShiftKhusus)
		api.GET("/shift-khusus/:id", middleware.DualAuthMiddleware(), pref.GetShiftKhususByID)
		api.POST("/shift-khusus", middleware.DualAuthMiddleware(), pref.PostShiftKhusus)
		api.GET("/shift-khusus/shift/:shift_id", middleware.DualAuthMiddleware(), pref.GetAllShiftKhususByShiftID)
		api.PUT("/shift-khusus/:id", middleware.DualAuthMiddleware(), pref.UpdateShiftKhusus)
		api.DELETE("/shift-khusus/:id", middleware.DualAuthMiddleware(), pref.DeleteShiftKhususByID)

		// ASN
		api.GET("/asn/data-utama", middleware.DualAuthMiddleware(), asn.GetAllPegawai)
		api.GET("/asn/pegawai-list", middleware.DualAuthMiddleware(), asn.GetPegawaiList)
		api.GET("/asn/pegawai-table", middleware.DualAuthMiddleware(), asn.GetPegawaiTable)

		// Lookup pegawai berdasarkan NIP.
		// Route spesifik harus sebelum /asn/data-utama/:id.
		api.GET("/asn/data-utama/nip/:nip", middleware.DualAuthMiddleware(), asn.GetPegawaiByNIP)
		api.POST("/asn/data-utama/by-nips", middleware.DualAuthMiddleware(), asn.PostPegawaiByNIPs)
		
		api.GET("/asn/data-utama/:id", middleware.DualAuthMiddleware(), asn.GetPegawaiByID)
		api.GET("/asn/data-utama/unit/:unit", middleware.DualAuthMiddleware(), asn.GetPegawaiByUnit)
		api.GET("/asn/data-utama/unit", middleware.DualAuthMiddleware(), asn.GetPegawaiByUnitHierarchy)
		api.GET("/asn/rw-pendidikan/:nip", middleware.DualAuthMiddleware(), asn.GetAllRWPendidikanByNIPs)
		api.GET("/asn/rw-pendidikan/id/:id", middleware.DualAuthMiddleware(), asn.GetRWPendidikanByID)

		// DINAS
		api.GET("/dinas", middleware.DualAuthMiddleware(), lain.GetDinasAlls)
		api.GET("/dinas/pegawai/:id", middleware.DualAuthMiddleware(), lain.GetDinasByPegawai)
		api.GET("/dinas/:id", middleware.DualAuthMiddleware(), lain.GetDinasByID)
		api.GET("/dinas/unit/:id", middleware.DualAuthMiddleware(), lain.GetDinasByUnit)
		api.GET("/dinas/atasan/:atasan", middleware.DualAuthMiddleware(), lain.GetDinasByAtasan)
		api.GET("/dinas/unit/hierarchy/:id", middleware.DualAuthMiddleware(), lain.GetDinasByUnitHierarchy)
		api.POST("/dinas", middleware.DualAuthMiddleware(), lain.CreateDinas)
		api.PUT("/dinas/:id", middleware.DualAuthMiddleware(), lain.UpdateDinas)
		api.DELETE("/dinas/:id", middleware.DualAuthMiddleware(), lain.DeleteDinas)

		// CUTI
		api.GET("/cuti", middleware.DualAuthMiddleware(), lain.GetCutiAlls)
		api.GET("/cuti/pegawai/:id", middleware.DualAuthMiddleware(), lain.GetCutiByPegawai)
		api.GET("/cuti/:id", middleware.DualAuthMiddleware(), lain.GetCutiByID)
		api.POST("/cuti", middleware.DualAuthMiddleware(), lain.CreateCuti)
		api.PUT("/cuti/:id", middleware.DualAuthMiddleware(), lain.UpdateCuti)
		api.PUT("/cuti/:id/status", middleware.DualAuthMiddleware(), lain.UpdateStatusCuti)
		api.DELETE("/cuti/:id", middleware.DualAuthMiddleware(), lain.DeleteCuti)

		// RIWAYAT GOLONGAN
		api.GET("/rw-golongan/id/:id", middleware.DualAuthMiddleware(), asn.GetRWGolonganByID)
		api.GET("/rw-golongan/:nip", middleware.DualAuthMiddleware(), asn.GetAllRWGolonganByNIP)
		api.POST("/rw-golongan", middleware.DualAuthMiddleware(), asn.PostRWGolongan)
		api.PUT("/rw-golongan/:id", middleware.DualAuthMiddleware(), asn.UpdateRWGolongan)
		api.DELETE("/rw-golongan/:id", middleware.DualAuthMiddleware(),	asn.DeleteRWGolongan)
	}

}
// Swagger khusus TEMANKU
func RegisterSwaggerTemanku(r *gin.Engine) {
    url := ginSwagger.URL("/docs/temanku/temanku_swagger.json")
    r.GET("/swagger/temanku/*any",
        ginSwagger.WrapHandler(
            swaggerFiles.Handler,
            url,
            ginSwagger.InstanceName("temanku"),
        ),
    )
}