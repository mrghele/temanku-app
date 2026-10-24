package asn

/*
   SINKRON DATA DARI SIASN UNTUK e-CARAKDEK
   ------------------------------------------------------------------
   POST /ecarakdek/api/v1/asn/sinkron/:jenis   body {"id":PNS_ID, "mode", "idx", "langkah"}

   e-Carakdek tidak terhubung ke SIASN BKN. Permintaan sync dari e-Carakdek diperiksa di sini
   (peran per jenis + pegawai dalam cakupan sekolah, lihat EcarakdekGuardBody("id")), lalu
   diteruskan ke gerbang sync di server SIMPEG yang menjalankan file sync SIMPEG yang sama:

     SIMPEG_SYNC_GATEWAY_URL = https://<domain simpeg>/sync-gateway/
     SIMPEG_SYNC_GATEWAY_KEY = sama dengan gateway.key di simpeg_config.php (min. 32 karakter)

   Hak per jenis mengikuti SIMPEG:
     pendidikan, golongan, penghargaan, utama : admin (asn.identitas.edit) & guru untuk data dirinya sendiri (portal guru)
     hukdis, kinerja, pak, cpns, jabatan, kursus, diklatpim, pasangan : admin & ASN (dirinya sendiri)
*/

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"
	"os"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
)

var ecarakdekSinkronAdminSaja = map[string]bool{
	"pendidikan": true, "golongan": true, "penghargaan": true, "utama": true,
}

var ecarakdekSinkronSemua = map[string]bool{
	"hukdis": true, "kinerja": true, "pak": true, "cpns": true,
	"jabatan": true, "kursus": true, "diklatpim": true, "pasangan": true,
}

var ecarakdekSinkronClient = &http.Client{Timeout: 175 * time.Second}

// EcarakdekGuardSinkron: jenis dikenal & peran boleh sync jenis tersebut.
func EcarakdekGuardSinkron() gin.HandlerFunc {
	return func(c *gin.Context) {
		s, ok := ecarakdekScopeFrom(c)
		jenis := c.Param("jenis")
		switch {
		case !ok:
			ecarakdekAbort(c, http.StatusForbidden, "Cakupan akses tidak diketahui")
		case ecarakdekSinkronAdminSaja[jenis]:
			// Guru/tendik (portal guru /v2/guru, seperti "Segarkan data" & Sync TEMANKU) boleh menyinkronkan
			// data dirinya sendiri; EcarakdekGuardBody("id") sesudah ini memastikan PNS_ID = milik akun.
			if s.Role != EcarakdekSuperAdmin && s.Role != EcarakdekAdminOPD && s.Role != EcarakdekPegawai {
				ecarakdekAbort(c, http.StatusForbidden, "Sinkron data ini hanya untuk admin")
				return
			}
			c.Next()
		case ecarakdekSinkronSemua[jenis]:
			c.Next()
		default:
			ecarakdekAbort(c, http.StatusNotFound, "Jenis sinkron tidak dikenal")
		}
	}
}

// PostEcarakdekSinkron meneruskan permintaan sync ke gerbang SIMPEG dan mengembalikan jawabannya apa adanya.
func PostEcarakdekSinkron(c *gin.Context) {
	url := strings.TrimSpace(os.Getenv("SIMPEG_SYNC_GATEWAY_URL"))
	key := strings.TrimSpace(os.Getenv("SIMPEG_SYNC_GATEWAY_KEY"))
	if url == "" || len(key) < 32 {
		c.JSON(http.StatusServiceUnavailable, gin.H{"success": false, "message": "Sinkron SIASN belum dikonfigurasi (SIMPEG_SYNC_GATEWAY_URL / SIMPEG_SYNC_GATEWAY_KEY)"})
		return
	}
	var in struct {
		ID      string `json:"id"`
		Mode    string `json:"mode"`
		Idx     *int   `json:"idx"`
		Langkah string `json:"langkah"`
	}
	if c.ShouldBindJSON(&in) != nil || !reRWID.MatchString(strings.TrimSpace(in.ID)) {
		c.JSON(http.StatusBadRequest, gin.H{"success": false, "message": "Data tidak valid"})
		return
	}
	body := map[string]interface{}{"jenis": c.Param("jenis"), "id": strings.TrimSpace(in.ID)}
	if in.Mode != "" {
		body["mode"] = in.Mode
	}
	if in.Idx != nil {
		body["idx"] = *in.Idx
	}
	if in.Langkah != "" {
		body["langkah"] = in.Langkah
	}
	b, _ := json.Marshal(body)
	req, err := http.NewRequestWithContext(c.Request.Context(), http.MethodPost, url, bytes.NewReader(b))
	if err != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"success": false, "message": "Gerbang sync SIMPEG tidak valid"})
		return
	}
	s, _ := ecarakdekScopeFrom(c)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Gateway-Key", key)
	req.Header.Set("X-Gateway-User", s.Username)
	res, err := ecarakdekSinkronClient.Do(req)
	if err != nil {
		c.JSON(http.StatusBadGateway, gin.H{"success": false, "offline": true, "message": "Server SIMPEG (sinkron SIASN) tidak dapat dihubungi"})
		return
	}
	defer res.Body.Close()
	out, _ := io.ReadAll(io.LimitReader(res.Body, 4<<20))
	if !json.Valid(out) {
		c.JSON(http.StatusBadGateway, gin.H{"success": false, "message": "Jawaban gerbang sync SIMPEG tidak valid (HTTP " + http.StatusText(res.StatusCode) + ")"})
		return
	}
	c.Data(res.StatusCode, "application/json; charset=utf-8", out)
}
