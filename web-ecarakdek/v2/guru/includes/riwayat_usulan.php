<?php
declare(strict_types=1);

/*
 * Usulan perubahan riwayat ASN dari TEMANKU (tambah / ubah / hapus), memakai mesin usulan API Go yang sama dengan SIMPEG
 * (asn/usulan.go: tabel usulan_riwayat). Riwayat itu sendiri tidak pernah diubah langsung: usulan diverifikasi admin
 * SIMPEG, lalu hasilnya tampil di notifikasi pegawai.
 *
 * Hak akses mengikuti peta permission SIMPEG untuk role "pegawai" (+ $_SESSION['user_permissions'] seperti can() SIMPEG).
 * PNS_ID selalu dari sesi (riwayat_pegawai()), tidak pernah dari input; kepemilikan usulan diperiksa sebelum ubah/batal.
 */

/** Kategori halaman Riwayat -> jenis usulan / endpoint rw-* API Go. */
const RIWAYAT_KATEGORI_JENIS = [
    'pendidikan' => ['pendidikan'], 'diklat' => ['kursus', 'diklatpim'], 'keluarga' => ['pasangan'],
    'pangkat' => ['golongan'], 'penghargaan' => ['penghargaan'], 'cpns' => ['cpns'], 'jabatan' => ['jabatan'],
    'tugas' => ['tugas'], 'kredit' => ['pak'], 'kinerja' => ['kinerja'], 'disiplin' => ['hukdis'],
];

const RIWAYAT_JENIS_LABEL = [
    'pendidikan' => 'Pendidikan', 'kursus' => 'Diklat / Kursus', 'diklatpim' => 'Diklat Struktural', 'pasangan' => 'Pasangan',
    'golongan' => 'Pangkat / Golongan', 'penghargaan' => 'Penghargaan', 'cpns' => 'CPNS/PNS', 'jabatan' => 'Jabatan',
    'tugas' => 'Tugas Tambahan', 'pak' => 'Angka Kredit', 'kinerja' => 'Kinerja (SKP)', 'hukdis' => 'Hukuman Disiplin',
];

/**
 * Hak usulan pegawai di TEMANKU per jenis (keputusan BKPSDM). Hukuman disiplin hanya baca.
 * Riwayat itu sendiri tidak berubah sebelum usulan diverifikasi admin SIMPEG.
 */
const RIWAYAT_IZIN_TEMANKU = [
    'pendidikan'  => ['ubah', 'hapus'],
    'kursus'      => ['tambah', 'ubah', 'hapus'],
    'diklatpim'   => ['tambah', 'ubah', 'hapus'],
    'pasangan'    => ['tambah', 'ubah', 'hapus'],
    'golongan'    => ['ubah'],
    'penghargaan' => ['tambah', 'ubah', 'hapus'],
    'cpns'        => ['ubah'],
    'jabatan'     => ['tambah', 'ubah', 'hapus'],
    'tugas'       => ['tambah', 'ubah', 'hapus'],
    'pak'         => ['ubah', 'hapus'],
    'kinerja'     => ['ubah', 'hapus'],
    'hukdis'      => [],
];

const RIWAYAT_REPO_KATEGORI = [
    'pendidikan' => 'pendidikan', 'golongan' => 'pangkat', 'penghargaan' => 'penghargaan', 'hukdis' => 'hukdis',
    'kinerja' => 'kinerja', 'pak' => 'kredit', 'cpns' => 'pns', 'jabatan' => 'jabatan', 'tugas' => 'jabatan',
    'kursus' => 'diklat', 'diklatpim' => 'diklat', 'pasangan' => 'pasangan',
];

const RIWAYAT_PDF_MAKS     = 1572864;   // 1,5 MB, sama dengan UU_MAX_PDF SIMPEG
const RIWAYAT_POLA_REPO    = '#^(pendidikan|pangkat|penghargaan|hukdis|kinerja|kredit|pns|jabatan|diklat|pasangan)/(riwayat|usul)/[A-Za-z0-9._-]+\.pdf$#';
const RIWAYAT_POLA_ID      = '/^[A-Za-z0-9-]{1,64}$/';
const RIWAYAT_STATUS_UBAH  = ['diajukan', 'dikembalikan'];

/** Izin per jenis untuk pegawai yang login; sync SIASN riwayat milik sendiri berlaku untuk semua kategori. */
function riwayat_izin_jenis(string $jenis): array
{
    $form = isset(riwayat_form_daftar()[$jenis]);
    $hak = RIWAYAT_IZIN_TEMANKU[$jenis] ?? [];
    return [
        'tambah' => $form && in_array('tambah', $hak, true),
        'ubah'   => $form && in_array('ubah', $hak, true),
        'hapus'  => in_array('hapus', $hak, true),
        'sync'   => true,
    ];
}

/* ===================== Definisi form usulan per jenis ===================== */

function riwayat_opsi_golongan(): array
{
    $o = [];
    foreach (RIWAYAT_GOLONGAN as $kode => [$gol, $pangkat]) $o[] = [(string)$kode, $gol . ' – ' . $pangkat];
    return $o;
}

/**
 * Field: k (kunci data usulan), l (label), t (text|number|int|decimal|date|year|select|search|textarea|check|nik|tel|email),
 * w (wajib), o (opsi [[nilai, label]]), ref (referensi dari riwayat_aksi.php?aksi=ref), nama (kunci label pilihan),
 * min/max, lebar 'penuh', bantu (teks bantuan). Dokumen: [kolom, label, wajib saat tambah].
 * Kunci lain dari riwayat lama (mis. instansi, lokasi, orang_id) ikut terkirim apa adanya pada usulan "ubah".
 */
function riwayat_form_daftar(): array
{
    static $f = null;
    if ($f !== null) return $f;
    $ekspektasi = [['1', 'Di atas ekspektasi'], ['2', 'Sesuai ekspektasi'], ['3', 'Di bawah ekspektasi']];
    $jkp = [];
    foreach (RIWAYAT_JENIS_KP as $k => $v) $jkp[] = [(string)$k, $v];
    $f = [
        'pendidikan' => ['field' => [
            ['k' => 'tk_pendidikan_id', 'l' => 'Tingkat pendidikan', 't' => 'select', 'w' => true, 'ref' => 'pendidikan', 'nama' => 'tk_pendidikan_nama'],
            ['k' => 'jurusan_id', 'l' => 'Jurusan / program studi', 't' => 'search', 'w' => true, 'ref' => 'jurusan', 'nama' => 'jurusan_nama', 'induk' => 'tk_pendidikan_id', 'lebar' => 'penuh', 'bantu' => 'Ketik minimal 2 huruf lalu pilih dari daftar.'],
            ['k' => 'nama_sekolah', 'l' => 'Nama sekolah / perguruan tinggi', 't' => 'text', 'w' => true, 'lebar' => 'penuh', 'max' => 255],
            ['k' => 'tahun_lulus', 'l' => 'Tahun lulus', 't' => 'year', 'w' => true],
            ['k' => 'tgl_lulus', 'l' => 'Tanggal lulus', 't' => 'date'],
            ['k' => 'no_ijazah', 'l' => 'Nomor ijazah', 't' => 'text', 'max' => 100],
            ['k' => 'gelar_depan', 'l' => 'Gelar depan', 't' => 'text', 'max' => 50],
            ['k' => 'gelar_belakang', 'l' => 'Gelar belakang', 't' => 'text', 'max' => 50],
            ['k' => 'pertama', 'l' => 'Pendidikan pertama (saat diangkat CPNS)', 't' => 'check'],
        ], 'dok' => [['ijazah', 'Ijazah', true], ['transkrip', 'Transkrip nilai', true], ['pertek', 'Pertek / izin belajar', false]]],

        'golongan' => ['field' => [
            ['k' => 'gol_kode', 'l' => 'Golongan / ruang', 't' => 'select', 'w' => true, 'o' => riwayat_opsi_golongan(), 'nama' => 'golongan'],
            ['k' => 'jenis', 'l' => 'Jenis kenaikan pangkat', 't' => 'select', 'w' => true, 'o' => $jkp],
            ['k' => 'tmt', 'l' => 'TMT golongan', 't' => 'date', 'w' => true],
            ['k' => 'no_sk', 'l' => 'Nomor SK', 't' => 'text', 'w' => true, 'max' => 255],
            ['k' => 'tgl_sk', 'l' => 'Tanggal SK', 't' => 'date', 'w' => true],
            ['k' => 'mk_tahun', 'l' => 'Masa kerja (tahun)', 't' => 'number', 'min' => 0, 'max' => 60],
            ['k' => 'mk_bulan', 'l' => 'Masa kerja (bulan)', 't' => 'number', 'min' => 0, 'max' => 11],
            ['k' => 'gaji', 'l' => 'Gaji pokok (Rp)', 't' => 'number', 'w' => true, 'min' => 100000, 'bantu' => 'Angka saja, mis. 2785700'],
            ['k' => 'nopertek', 'l' => 'Nomor pertek BKN', 't' => 'text', 'max' => 100],
            ['k' => 'tglpertek', 'l' => 'Tanggal pertek BKN', 't' => 'date'],
        ], 'dok' => [['sk', 'SK kenaikan pangkat', true], ['pertek', 'Pertek BKN', false]]],

        'penghargaan' => ['field' => [
            ['k' => 'harga_id', 'l' => 'Jenis penghargaan', 't' => 'select', 'w' => true, 'ref' => 'penghargaan', 'nama' => 'harga_nama', 'lebar' => 'penuh'],
            ['k' => 'tahun', 'l' => 'Tahun', 't' => 'year', 'w' => true],
            ['k' => 'sk_nomor', 'l' => 'Nomor SK', 't' => 'text', 'w' => true, 'max' => 255],
            ['k' => 'sk_date', 'l' => 'Tanggal SK', 't' => 'date', 'w' => true],
        ], 'dok' => [['sertifikat', 'Sertifikat / SK penghargaan', true]]],

        'kinerja' => ['field' => [
            ['k' => 'tahun', 'l' => 'Tahun penilaian', 't' => 'year', 'w' => true, 'min' => 2022],
            ['k' => 'hasil', 'l' => 'Hasil kerja', 't' => 'select', 'w' => true, 'o' => $ekspektasi],
            ['k' => 'perilaku', 'l' => 'Perilaku kerja', 't' => 'select', 'w' => true, 'o' => $ekspektasi],
            ['k' => 'status_penilai', 'l' => 'Status pejabat penilai', 't' => 'select', 'o' => [['ASN', 'ASN'], ['NON ASN', 'Non ASN']]],
            ['k' => 'penilai_nip', 'l' => 'NIP pejabat penilai', 't' => 'text', 'max' => 30],
            ['k' => 'penilai_nama', 'l' => 'Nama pejabat penilai', 't' => 'text', 'max' => 255],
            ['k' => 'penilai_jabatan', 'l' => 'Jabatan pejabat penilai', 't' => 'text', 'max' => 255, 'lebar' => 'penuh'],
            ['k' => 'penilai_unor', 'l' => 'Unit kerja pejabat penilai', 't' => 'text', 'max' => 255, 'lebar' => 'penuh'],
            ['k' => 'penilai_golongan', 'l' => 'Golongan pejabat penilai', 't' => 'select', 'o' => riwayat_opsi_golongan()],
        ], 'dok' => [['skp', 'Dokumen SKP', true]], 'kunci_ubah' => true,
            'catatan' => 'Data kinerja mengikuti SIASN / e-Kinerja dan dikunci. Yang dapat diperbarui hanya dokumen SKP.'],

        'pak' => ['field' => [
            ['k' => 'no_sk', 'l' => 'Nomor SK PAK', 't' => 'text', 'w' => true, 'max' => 255],
            ['k' => 'tgl_sk', 'l' => 'Tanggal SK', 't' => 'date', 'w' => true],
            ['k' => 'bln_mulai', 'l' => 'Bulan mulai penilaian', 't' => 'number', 'w' => true, 'min' => 1, 'max' => 12],
            ['k' => 'thn_mulai', 'l' => 'Tahun mulai penilaian', 't' => 'year', 'w' => true],
            ['k' => 'bln_selesai', 'l' => 'Bulan selesai penilaian', 't' => 'number', 'w' => true, 'min' => 1, 'max' => 12],
            ['k' => 'thn_selesai', 'l' => 'Tahun selesai penilaian', 't' => 'year', 'w' => true],
            ['k' => 'utama', 'l' => 'Angka kredit unsur utama', 't' => 'decimal', 'w' => true],
            ['k' => 'penunjang', 'l' => 'Angka kredit unsur penunjang', 't' => 'decimal'],
            ['k' => 'pertama', 'l' => 'PAK pertama', 't' => 'check'],
            ['k' => 'integrasi', 'l' => 'PAK integrasi', 't' => 'check'],
            ['k' => 'konversi', 'l' => 'PAK konversi', 't' => 'check'],
        ], 'dok' => [['dok_pak', 'Dokumen PAK', false], ['sk_pak', 'SK PAK', false]], 'catatan' => 'Minimal satu dokumen (Dokumen PAK atau SK PAK) wajib diunggah.'],

        'cpns' => ['field' => [
            // sama dengan form Data CPNS/PNS SIMPEG: bagian PNS & syarat pengangkatan hanya untuk status PNS
            ['k' => 'status', 'l' => 'Status kepegawaian', 't' => 'select', 'w' => true, 'o' => [['CPNS', 'CPNS'], ['PNS', 'PNS']]],
            ['k' => '_sec_cpns', 'l' => 'CPNS', 't' => 'judul'],
            ['k' => 'no_sk_cpns', 'l' => 'Nomor SK CPNS', 't' => 'text', 'w' => true, 'max' => 255],
            ['k' => 'tgl_sk_cpns', 'l' => 'Tanggal SK CPNS', 't' => 'date', 'w' => true],
            ['k' => 'jabatan_angkat', 'l' => 'Jabatan angkat CPNS', 't' => 'text', 'max' => 255],
            ['k' => 'tmt_cpns', 'l' => 'TMT CPNS', 't' => 'date'],
            ['k' => '_sec_pns', 'l' => 'PNS', 't' => 'judul', 'tampil' => ['status', 'PNS']],
            ['k' => 'no_sk_pns', 'l' => 'Nomor SK PNS', 't' => 'text', 'w' => true, 'max' => 255, 'tampil' => ['status', 'PNS']],
            ['k' => 'tgl_sk_pns', 'l' => 'Tanggal SK PNS', 't' => 'date', 'w' => true, 'tampil' => ['status', 'PNS']],
            ['k' => 'tmt_pns', 'l' => 'TMT PNS', 't' => 'date', 'w' => true, 'tampil' => ['status', 'PNS']],
            ['k' => '_sec_lain', 'l' => 'Data lainnya', 't' => 'judul'],
            ['k' => 'no_spmt', 'l' => 'Nomor SPMT', 't' => 'text', 'max' => 100],
            ['k' => 'tgl_spmt', 'l' => 'Tanggal SPMT', 't' => 'date'],
            ['k' => 'no_sttpl', 'l' => 'Nomor STTPL', 't' => 'text', 'max' => 100, 'tampil' => ['status', 'PNS']],
            ['k' => 'tgl_sttpl', 'l' => 'Tanggal STTPL', 't' => 'date', 'tampil' => ['status', 'PNS']],
            ['k' => 'no_dokter', 'l' => 'Nomor surat keterangan dokter', 't' => 'text', 'max' => 100, 'tampil' => ['status', 'PNS']],
            ['k' => 'tgl_dokter', 'l' => 'Tanggal surat keterangan dokter', 't' => 'date', 'tampil' => ['status', 'PNS']],
            ['k' => 'pertek_nomor', 'l' => 'Nomor Pertek CPNS/PNS > 2 tahun', 't' => 'text', 'max' => 100, 'tampil' => ['status', 'PNS'],
                'bantu' => 'Pertek BKN untuk CPNS yang diangkat PNS lebih dari 2 tahun.'],
            ['k' => 'pertek_tgl', 'l' => 'Tanggal Pertek CPNS/PNS > 2 tahun', 't' => 'date', 'tampil' => ['status', 'PNS']],
            ['k' => 'karpeg', 'l' => 'Kartu pegawai', 't' => 'text', 'max' => 50],
            ['k' => 'tmt_tugas', 'l' => 'TMT melaksanakan tugas', 't' => 'date'],
        ], 'dok' => [['sk_cpns', 'SK CPNS', false], ['sk_pns', 'SK PNS', false, ['status', 'PNS']], ['spmt', 'SPMT', false]],
            'catatan' => 'Status yang sudah PNS tidak dapat dikembalikan ke CPNS. SIASN mewajibkan SK PNS saat data PNS dikirim.'],

        'kursus' => ['field' => [
            ['k' => 'jenis_diklat_id', 'l' => 'Jenis diklat', 't' => 'select', 'w' => true, 'ref' => 'diklat', 'nama' => 'jenis_diklat_nama'],
            ['k' => 'nama', 'l' => 'Nama diklat / kursus', 't' => 'text', 'w' => true, 'lebar' => 'penuh', 'max' => 500],
            ['k' => 'penyelenggara', 'l' => 'Institusi penyelenggara', 't' => 'text', 'w' => true, 'lebar' => 'penuh', 'max' => 500],
            ['k' => 'no_sertifikat', 'l' => 'Nomor sertifikat', 't' => 'text', 'w' => true, 'max' => 255],
            ['k' => 'jam', 'l' => 'Jumlah jam (JP)', 't' => 'int', 'w' => true, 'min' => 1, 'max' => 10000],
            ['k' => 'tgl_mulai', 'l' => 'Tanggal mulai', 't' => 'date', 'w' => true],
            ['k' => 'tgl_selesai', 'l' => 'Tanggal selesai', 't' => 'date', 'w' => true],
            ['k' => 'tahun', 'l' => 'Tahun', 't' => 'year'],
        ], 'dok' => [['sertifikat', 'Sertifikat', true]]],

        'diklatpim' => ['field' => [
            ['k' => 'latihan_id', 'l' => 'Jenis diklat struktural', 't' => 'select', 'w' => true, 'ref' => 'diklatpim', 'nama' => 'latihan_nama', 'lebar' => 'penuh'],
            ['k' => 'nomor', 'l' => 'Nomor sertifikat', 't' => 'text', 'w' => true, 'max' => 255],
            ['k' => 'penyelenggara', 'l' => 'Institusi penyelenggara', 't' => 'text', 'w' => true, 'max' => 500],
            ['k' => 'jam', 'l' => 'Jumlah jam (JP)', 't' => 'int', 'w' => true, 'min' => 1, 'max' => 10000],
            ['k' => 'bobot', 'l' => 'Bobot', 't' => 'int', 'min' => 0],
            ['k' => 'tgl_mulai', 'l' => 'Tanggal mulai', 't' => 'date', 'w' => true],
            ['k' => 'tgl_selesai', 'l' => 'Tanggal selesai', 't' => 'date', 'w' => true],
            ['k' => 'tahun', 'l' => 'Tahun', 't' => 'year'],
            ['k' => 'jenis_kompetensi', 'l' => 'Jenis kompetensi', 't' => 'text', 'max' => 255],
        ], 'dok' => [['sertifikat', 'Sertifikat', true]]],

        'jabatan' => ['field' => riwayat_field_jabatan(false), 'dok' => [['sk', 'SK jabatan', false], ['lantik', 'Dokumen pelantikan', false], ['sk_mutasi', 'SK mutasi', false, ['jenis', ['2', '4']]]],
            'catatan' => 'SK jabatan atau SK mutasi wajib diunggah untuk riwayat baru (kecuali Mutasi Unor: SK riwayat dasar dipakai).'],
        'tugas' => ['field' => riwayat_field_jabatan(true), 'dok' => [['sk', 'SK tugas tambahan', true], ['lantik', 'Dokumen pelantikan', false]]],

        'pasangan' => ['field' => [
            ['k' => 'pasangan_ke', 'l' => 'Pasangan ke-', 't' => 'select', 'w' => true, 'o' => [['1', '1'], ['2', '2'], ['3', '3'], ['4', '4']]],
            ['k' => 'nama', 'l' => 'Nama pasangan', 't' => 'text', 'w' => true, 'max' => 255],
            ['k' => 'jenis_kelamin', 'l' => 'Jenis kelamin', 't' => 'select', 'o' => [['L', 'Laki-laki'], ['P', 'Perempuan']]],
            ['k' => 'tempat_lahir', 'l' => 'Tempat lahir', 't' => 'text', 'max' => 255],
            ['k' => 'tgl_lahir', 'l' => 'Tanggal lahir', 't' => 'date', 'w' => true],
            ['k' => 'nomor_identitas', 'l' => 'NIK', 't' => 'nik', 'bantu' => '16 digit angka'],
            ['k' => 'agama_id', 'l' => 'Agama', 't' => 'select', 'w' => true, 'ref' => 'agama', 'nama' => 'agama_nama'],
            ['k' => 'no_hp', 'l' => 'Nomor HP', 't' => 'tel', 'max' => 20],
            ['k' => 'email', 'l' => 'Email', 't' => 'email', 'max' => 100],
            ['k' => 'alamat', 'l' => 'Alamat', 't' => 'textarea', 'lebar' => 'penuh', 'max' => 500],
            ['k' => 'is_pns', 'l' => 'Pekerjaan', 't' => 'select', 'o' => [['0', 'Bukan PNS'], ['1', 'PNS']]],
            ['k' => 'status_pernikahan', 'l' => 'Status pernikahan', 't' => 'select', 'w' => true, 'o' => [['1', 'Menikah'], ['2', 'Cerai'], ['3', 'Meninggal']]],
            ['k' => 'no_akta_menikah', 'l' => 'Nomor akta / buku nikah', 't' => 'text', 'w' => true, 'max' => 255],
            ['k' => 'tgl_akta_menikah', 'l' => 'Tanggal menikah', 't' => 'date', 'w' => true],
            ['k' => 'no_akta_cerai', 'l' => 'Nomor akta cerai', 't' => 'text', 'max' => 255, 'tampil' => ['status_pernikahan', '2']],
            ['k' => 'tgl_cerai', 'l' => 'Tanggal cerai', 't' => 'date', 'tampil' => ['status_pernikahan', '2']],
            ['k' => 'no_akta_meninggal', 'l' => 'Nomor surat keterangan kematian', 't' => 'text', 'max' => 255, 'tampil' => ['status_pernikahan', '3']],
            ['k' => 'tgl_meninggal', 'l' => 'Tanggal meninggal', 't' => 'date', 'tampil' => ['status_pernikahan', '3']],
        ], 'dok' => [['akta', 'Buku / akta nikah', false]],
            'catatan' => 'Data pasangan dikirim ke SIASN BKN oleh admin setelah usulan disetujui (buku/akta nikah ikut dikirim). Pada urutan pasangan yang sama, nama tidak boleh diganti: bila menikah lagi gunakan Tambah dengan urutan berikutnya. Perubahan NIK tidak dapat dikirim ke SIASN dari aplikasi — hubungi admin BKPSDM.'],
    ];
    return $f;
}

/** Field form jabatan / tugas tambahan (struktur sama dengan DataJabatanUsul API Go). */
function riwayat_field_jabatan(bool $tugas): array
{
    $f = [];
    if ($tugas) {
        $f[] = ['k' => 'jenis', 'l' => 'Jenis jabatan', 't' => 'tetap', 'nilai' => '1'];
        $f[] = ['k' => 'jenis_penugasan', 'l' => 'Jenis penugasan', 't' => 'select', 'w' => true, 'o' => [['TT', 'Tugas tambahan (mis. Kepala Sekolah)'], ['PLT', 'Pelaksana Tugas (Plt.)'], ['PJ', 'Penjabat (Pj.)']]];
    } else {
        $f[] = ['k' => 'jenis', 'l' => 'Jenis jabatan', 't' => 'select', 'w' => true, 'o' => [['1', 'Struktural'], ['2', 'Fungsional'], ['4', 'Pelaksana']]];
    }
    if (!$tugas) {
        $f[] = ['k' => 'jenis_mutasi', 'l' => 'Jenis mutasi', 't' => 'select', 'kosong' => '— Tidak ada —', 'o' => [['MJ', 'Mutasi Jabatan (MJ)'], ['MU', 'Mutasi Unor (MU)']], 'tampil' => ['jenis', ['2', '4']]];
    }
    $f[] = ['k' => 'unor_id', 'l' => 'Unit organisasi', 't' => 'search', 'w' => true, 'ref' => 'unor', 'nama' => 'unor_nama', 'lebar' => 'penuh', 'bantu' => 'Ketik minimal 2 huruf.'];
    // Struktural (dan tugas tambahan): jabatan mengikuti unit organisasi, tampil hanya-baca seperti SIMPEG.
    $f[] = ['k' => '_struk', 'l' => 'Jabatan struktural (otomatis dari unit)', 't' => 'struk', 'lebar' => 'penuh', 'tampil' => ['jenis', '1'],
        'bantu' => $tugas ? 'Tugas tambahan selalu menempati jabatan struktural pada unit yang dipilih.' : ''];
    if (!$tugas) {
        $f[] = ['k' => 'fungsional_id', 'l' => 'Jabatan fungsional', 't' => 'search', 'w' => true, 'ref' => 'fungsional', 'nama' => 'jabatan_nama', 'lebar' => 'penuh', 'tampil' => ['jenis', '2']];
        $f[] = ['k' => 'umum_id', 'l' => 'Jabatan pelaksana', 't' => 'search', 'w' => true, 'ref' => 'pelaksana', 'nama' => 'jabatan_nama', 'lebar' => 'penuh', 'tampil' => ['jenis', '4']];
    }
    $f[] = ['k' => '_sec_sk', 'l' => 'Surat keputusan', 't' => 'judul'];
    $f[] = ['k' => 'no_sk', 'l' => 'Nomor SK', 't' => 'text', 'w' => true, 'max' => 255];
    $f[] = ['k' => 'tgl_sk', 'l' => 'Tanggal SK', 't' => 'date', 'w' => true];
    $f[] = ['k' => 'tmt_jabatan', 'l' => $tugas ? 'TMT tugas' : 'TMT jabatan', 't' => 'date', 'w' => true];
    $f[] = ['k' => 'tmt_pelantikan', 'l' => 'TMT pelantikan', 't' => 'date', 'tampil' => ['jenis', '1']];
    if (!$tugas) {
        $f[] = ['k' => 'tmt_mutasi', 'l' => 'TMT mutasi', 't' => 'date', 'w' => true, 'tampil' => ['jenis_mutasi', 'MU']];
        // Mutasi Unor: riwayat dasar (MJ terakhir) + SK-nya, seperti "Dokumen Dasar Mutasi Unor" SIMPEG
        $f[] = ['k' => '_dasar_mu', 'l' => 'Dokumen dasar Mutasi Unor', 't' => 'dasar_mu', 'lebar' => 'penuh', 'tampil' => ['jenis_mutasi', 'MU']];
    }
    return $f;
}

/** Kolom dokumen usulan -> kolom dokumen riwayat (riwayat_baris_*), untuk pratinjau dokumen saat ini di form "ubah". */
const RIWAYAT_DOK_RIWAYAT = [
    'golongan' => ['sk' => 'scan', 'pertek' => 'dok_pertek'], 'penghargaan' => ['sertifikat' => 'scan'],
    'kinerja' => ['skp' => 'scan'], 'hukdis' => ['sk' => 'scan'],
];

/** Nama kolom riwayat (GET rw-*) yang berbeda dengan kunci data usulan, per jenis. */
const RIWAYAT_USUL_ALIAS = [
    'pendidikan'  => ['jurusan' => 'jurusan_nama'],
    'golongan'    => ['id_gol' => 'gol_kode', 'tmt_tgl' => 'tmt', 'tahun' => 'mk_tahun', 'bulan' => 'mk_bulan'],
    'penghargaan' => ['jenis' => 'harga_id', 'jenis_nama' => 'harga_nama', 'no_sk' => 'sk_nomor', 'tgl_sk' => 'sk_date'],
];

/** Kolom berkas per jenis (tidak pernah dikirim ke browser sebagai nilai awal). */
function riwayat_kolom_berkas(string $jenis): array
{
    $form = riwayat_form_daftar()[$jenis] ?? null;
    $kolom = $form ? array_column($form['dok'], 0) : [];
    return array_merge($kolom, ['scan', 'dok_pertek', 'ijazah', 'transkrip', 'pertek', 'sk', 'lantik', 'sk_mutasi', 'sertifikat', 'skp', 'dok_pak', 'sk_pak', 'sk_cpns', 'sk_pns', 'spmt', 'akta']);
}

/** Nilai awal form "ubah" dari satu baris riwayat: hanya nilai skalar, tanpa path berkas, kunci disamakan dengan data usulan. */
function riwayat_usul_awal(string $jenis, array $g): array
{
    $berkas = array_flip(riwayat_kolom_berkas($jenis));
    $alias = RIWAYAT_USUL_ALIAS[$jenis] ?? [];
    $out = [];
    foreach ($g as $k => $v) {
        if (!is_string($k) || isset($berkas[$k]) || !(is_scalar($v) || $v === null)) continue;
        $v = trim((string)$v);
        if (preg_match(RIWAYAT_DOK_POLA, $v)) continue;
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]/', $v)) $v = substr($v, 0, 10);   // tanggal ISO lengkap -> Y-m-d
        if ($v === '0000-00-00') $v = '';
        $out[$alias[$k] ?? $k] = $v;
    }
    if ($jenis === 'pendidikan' && ($out['jurusan_nama'] ?? '') === '' && isset($g['jurusan_nama'])) $out['jurusan_nama'] = (string)$g['jurusan_nama'];
    return $out;
}

/* ===================== Panggilan API Go (dengan pesan galat) ===================== */

/**
 * Permintaan JSON ke API_TEMANKU dengan token layanan + SSO; mengembalikan [kode HTTP, data JSON].
 * Berbeda dengan api_request(): pesan galat API (mis. validasi usulan) tetap terbaca.
 */
function riwayat_api_kirim(string $method, string $endpoint, ?array $data = null, int $timeout = 30): array
{
    $token = api_service_token();
    if (!$token) return [0, null];
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
    if (!empty($_SESSION['access_token'])) $headers[] = 'Auth: Bearer ' . $_SESSION['access_token'];
    $ch = curl_init(rtrim(API_TEMANKU, '/') . $endpoint);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($data !== null) {
        $opt[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }
    $opt[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opt);
    $res  = curl_exec($ch);
    $kode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($res) ? json_decode($res, true) : null;
    return [$kode, $json];
}

/** Pesan galat API Go yang aman ditampilkan (teks pendek tanpa tag). */
function riwayat_api_pesan($json, string $cadangan): string
{
    $m = is_array($json) ? ($json['error'] ?? ($json['message'] ?? '')) : '';
    $m = is_string($m) ? trim(strip_tags($m)) : '';
    return $m !== '' ? (function_exists('mb_substr') ? mb_substr($m, 0, 300) : substr($m, 0, 300)) : $cadangan;
}

/**
 * Unggah PDF ke repository dokumen (Go /upload-dok). Dipakai untuk usulan (tipe usul) dan sync SIASN (tipe riwayat).
 * @throws RuntimeException
 */
function riwayat_unggah_pdf(string $isi, string $refId, string $kode, string $dokId, string $tipe, string $kategori): string
{
    if (!in_array($kategori, RIWAYAT_REPO_KATEGORI, true)) throw new RuntimeException('Kategori dokumen tidak dikenal');
    if (strncmp($isi, '%PDF-', 5) !== 0) throw new RuntimeException('Berkas bukan PDF');
    $token = api_service_token();
    if (!$token) throw new RuntimeException('Token layanan API tidak tersedia');
    $refId = preg_replace('/[^A-Za-z0-9-]/', '', $refId) ?: 'x';
    $tmp = tempnam(sys_get_temp_dir(), 'rwdok_');
    if ($tmp === false) throw new RuntimeException('Gagal menyiapkan berkas sementara');
    try {
        file_put_contents($tmp, $isi);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
        if (!empty($_SESSION['access_token'])) $headers[] = 'Auth: Bearer ' . $_SESSION['access_token'];
        $ch = curl_init(rtrim(API_TEMANKU, '/') . '/upload-dok');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => [
                'kategori' => $kategori,
                'tipe' => $tipe === 'usul' ? 'usul' : 'riwayat',
                'ref_id' => $refId,
                'id_ref_dokumen' => preg_replace('/\D/', '', $dokId) ?: '0',
                'kode_dokumen' => preg_replace('/[^a-z_]/', '', $kode),
                'file' => new CURLFile($tmp, 'application/pdf', preg_replace('/[^a-z_]/', '', $kode) . '_' . $refId . '.pdf'),
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $res  = curl_exec($ch);
        $kodeHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } finally {
        @unlink($tmp);
    }
    $json = is_string($res) ? json_decode($res, true) : null;
    $path = is_array($json) ? trim((string)($json['filePath'] ?? '')) : '';
    if ($kodeHttp < 200 || $kodeHttp >= 300 || !preg_match(RIWAYAT_POLA_REPO, $path)) {
        error_log('TEMANKU riwayat upload-dok HTTP ' . $kodeHttp . ': ' . substr((string)$res, 0, 300));
        throw new RuntimeException('Dokumen belum berhasil diunggah ke repository (' . riwayat_api_pesan($json, 'HTTP ' . $kodeHttp) . ')');
    }
    return $path;
}

/* ===================== Usulan milik pegawai ===================== */

/** Semua usulan pegawai (tanpa yang dibatalkan); null bila API gagal. */
function riwayat_usulan_daftar(array $pegawai): ?array
{
    [$kode, $json] = riwayat_api_kirim('GET', '/asn/usulan-riwayat?' . http_build_query(['pns_id' => $pegawai['pns_id']]));
    if ($kode < 200 || $kode >= 300 || !is_array($json)) {
        error_log('TEMANKU riwayat: daftar usulan gagal (HTTP ' . $kode . ')');
        return null;
    }
    $out = [];
    foreach (api_rows($json) as $u) {
        if (is_array($u) && ($u['pns_id'] ?? '') === $pegawai['pns_id']) $out[] = $u;
    }
    return $out;
}

/** Satu usulan milik pegawai yang login; null bila tidak ada / milik orang lain. */
function riwayat_usulan_milik(array $pegawai, string $id): ?array
{
    if (!preg_match(RIWAYAT_POLA_ID, $id)) return null;
    [$kode, $json] = riwayat_api_kirim('GET', '/asn/usulan-riwayat/' . rawurlencode($id));
    if ($kode !== 200 || !is_array($json)) return null;
    // Go mengirim struct UsulanRiwayat langsung (field "data" = isi usulan, bukan pembungkus).
    $u = !isset($json['id']) && isset($json['data']['id']) && is_array($json['data']) ? $json['data'] : $json;
    return is_string($u['pns_id'] ?? null) && hash_equals($pegawai['pns_id'], $u['pns_id']) ? $u : null;
}

function riwayat_json_objek($v): array
{
    if (is_string($v)) $v = json_decode($v, true);
    return is_array($v) ? $v : [];
}

/** Bentuk usulan untuk browser: data tanpa path berkas (hanya penanda ada/tidak), tanpa nama akun verifikator. */
function riwayat_usulan_publik(array $u): array
{
    $jenis = (string)($u['jenis'] ?? '');
    $kolomDok = array_column((riwayat_form_daftar()[$jenis]['dok'] ?? []), 0);
    $bersih = static function (array $d) use ($jenis): array {
        $o = [];
        foreach ($d as $k => $v) {
            if (!is_string($k) || !(is_scalar($v) || $v === null)) continue;
            $v = (string)$v;
            if (preg_match(RIWAYAT_POLA_REPO, $v) || preg_match(RIWAYAT_DOK_POLA, $v)) continue;
            $o[$k] = $v;
        }
        return $o;
    };
    $data = riwayat_json_objek($u['data'] ?? null);
    $lama = riwayat_json_objek($u['data_lama'] ?? null);
    $dok = [];
    foreach ($kolomDok as $k) {
        if (preg_match(RIWAYAT_POLA_REPO, (string)($data[$k] ?? ''))) $dok[] = $k;
    }
    $jejak = [];
    foreach (riwayat_json_objek($u['riwayat_verifikasi'] ?? null) as $j) {
        if (is_array($j)) $jejak[] = ['status' => (string)($j['status'] ?? ''), 'catatan' => (string)($j['catatan'] ?? ''), 'waktu' => (string)($j['waktu'] ?? '')];
    }
    return [
        'id' => (string)($u['id'] ?? ''), 'jenis' => $jenis, 'jenis_label' => RIWAYAT_JENIS_LABEL[$jenis] ?? $jenis,
        'aksi' => (string)($u['aksi'] ?? ''), 'ref_id' => (string)($u['ref_id'] ?? ''), 'status' => (string)($u['status'] ?? ''),
        'catatan' => (string)($u['catatan'] ?? ''), 'alasan' => (string)($u['alasan'] ?? ''),
        'diajukan_at' => (string)($u['diajukan_at'] ?? ''), 'diverifikasi_at' => (string)($u['diverifikasi_at'] ?? ''),
        'diubah_at' => (string)($u['diubah_at'] ?? ''), 'revisi' => (int)($u['revisi'] ?? 0),
        'data' => $bersih($data), 'data_lama' => $bersih($lama), 'dok' => $dok, 'jejak' => $jejak,
    ];
}

/**
 * Data usulan dari isian form: hanya kunci field yang didefinisikan (+ label pilihan), di atas data dasar
 * (riwayat lama untuk "ubah", data usulan lama untuk edit usulan). Tipe angka disesuaikan dengan struct Go.
 */
function riwayat_usul_data(string $jenis, array $dasar, array $isian): array
{
    $form = riwayat_form_daftar()[$jenis];
    $data = $dasar;
    $potong = static function (string $s, int $max): string {
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    };
    // Field bersyarat (mis. jabatan fungsional hanya untuk jenis 2) dinilai dari isian yang dikirim.
    $def = array_column($form['field'], null, 'k');
    // Field bersyarat bertingkat: field aktif bila syaratnya terpenuhi DAN field syaratnya sendiri aktif
    // (mis. TMT mutasi hanya untuk MU, dan jenis mutasi hanya untuk fungsional/pelaksana).
    $aktif = static function (array $f, int $dalam = 0) use (&$aktif, $isian, $dasar, $def): bool {
        if (empty($f['tampil'])) return true;
        [$dep, $nilai] = $f['tampil'];
        if ($dalam < 4 && isset($def[$dep]) && !$aktif($def[$dep], $dalam + 1)) return false;
        $v = (string)($isian[$dep] ?? ($dasar[$dep] ?? ''));
        return in_array($v, (array)$nilai, true);
    };
    foreach ($form['field'] as $f) {
        $k = $f['k'];
        if ($f['t'] === 'tetap') { $data[$k] = (string)$f['nilai']; continue; }
        if (in_array($f['t'], ['judul', 'struk', 'dasar_mu'], true)) continue;   // hanya tampilan
        if (!$aktif($f)) { if (!empty($f['tampil']) && array_key_exists($k, $data) && $f['t'] !== 'search') $data[$k] = ''; continue; }
        if (!array_key_exists($k, $isian)) continue;   // tidak dikirim: nilai dasar dipertahankan
        $v = $isian[$k];
        $v = is_string($v) ? trim($v) : '';
        if ($f['t'] === 'check') $v = $v === '1' ? '1' : '0';
        if (in_array($f['t'], ['date'], true) && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $v = '';
        if (in_array($f['t'], ['decimal'], true)) $v = str_replace(',', '.', $v);
        $data[$k] = $potong($v, (int)($f['max'] ?? 255));
        if (!empty($f['nama'])) {
            $n = $isian[$f['nama']] ?? '';
            $data[$f['nama']] = $potong(is_string($n) ? trim(strip_tags($n)) : '', 255);
        }
    }
    // Field angka bulat di struct Go (int): kirim sebagai angka JSON.
    $bulat = ['kursus' => ['jam'], 'diklatpim' => ['jam', 'bobot'], 'pasangan' => ['pasangan_ke', 'is_pns']][$jenis] ?? [];
    foreach ($bulat as $k) $data[$k] = (int)($data[$k] ?? 0);
    // Field string lain tetap string (struct Go memakai string).
    foreach ($data as $k => $v) {
        if (!in_array($k, $bulat, true) && !is_string($v)) $data[$k] = is_scalar($v) ? (string)$v : '';
    }
    return $data;
}
