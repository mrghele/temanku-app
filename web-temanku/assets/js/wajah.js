// Perekaman & uji verifikasi wajah (Web).
// Browser hanya: kamera, deteksi wajah, panduan gerakan (teks, panah, suara) dan
// mengirim frame JPEG. Embedding/template dibuat & disimpan terenkripsi di server.
// Tidak ada data wajah yang disimpan di browser.
const $ = selector => document.querySelector(selector);
const root = $('#face-app');

// ---- Elemen halaman ----
const statusIcon = $('#face-status-icon');
const statusTitle = $('#face-status-title');
const statusSub = $('#face-status-sub');
const review = $('#face-review');
const simpegPhoto = $('#face-simpeg-photo');
const simpegEmpty = $('#face-simpeg-empty');
const snapshot = $('#face-snapshot');
const snapshotEmpty = $('#face-snapshot-empty');
const compareResult = $('#face-compare-result');
const mainButton = $('#face-main');
const simulasiButton = $('#face-simulasi');
const noBlinkNote = $('#face-no-blink');
// Mode absen (absen.php): absen sungguhan dengan wajah.
const ABSEN = root.dataset.mode === 'absen';
const absenLabels = { masuk: 'masuk', pulang: 'pulang', wfa: 'masuk WFA', upacara: 'upacara', dinas: 'dinas dalam', dinasluar: 'dinas luar' };

// ---- Elemen popup ----
const modal = $('#face-modal');
const video = $('#face-video');
const ring = $('#face-ring-progress');
const arrowLeft = $('#face-arrow-left');
const arrowRight = $('#face-arrow-right');
const blinkIcon = $('#face-blink');
const resultIcon = $('#face-result-icon');
const instruction = $('#face-instruction');
const hint = $('#face-hint');
const actions = $('#face-modal-actions');
const doneButton = $('#face-done');
const retryButton = $('#face-retry');
const closeButton = $('#face-close');
const muteButton = $('#face-mute');
const choosePanel = $('#face-choose');
const stage = $('#face-stage');
const checksList = $('#face-checks');

// Batas pose SAMA dengan server (app/liveness.py) dan Android. Hanya panduan;
// keputusan liveness tetap di server.
const FRONTAL_MAX = 12, TURN_MIN = 15, TURN_MAX = 40, AMBIGUOUS = 90;
const FRAME_INTERVAL_MS = 125, BLINK_INTERVAL_MS = 60, MAX_FRAMES = 200, CAPTURE_MAX_MS = 30000;
// Ambang kedip SAMA dengan server (app/liveness.py).
const BLINK_CLOSED_MEAN = 0.45, BLINK_CLOSED_EACH = 0.30, BLINK_OPEN = 0.25;
const eyesClosed = (l, r) => (l + r) / 2 >= BLINK_CLOSED_MEAN && Math.min(l, r) >= BLINK_CLOSED_EACH;
const eyesOpen = (l, r) => l <= BLINK_OPEN && r <= BLINK_OPEN;
const RING_LENGTH = 351.86; // keliling lingkaran r=56
const FRONTAL_MIN_MS = 900;  // langkah 'lihat lurus' tampil minimal 0,9 dtk agar instruksi sempat terbaca/terdengar
// Verifikasi/simulasi: 1 gerakan acak, klip singkat. Angka minimum SAMA dengan server
// (liveness.CLIP_RULES verify: >= 8 frame & 1,2 dtk), diberi sedikit cadangan.
const QUICK = { frontalMinMs: 450, minFrames: 10, minMs: 1500 };
const FULL = { frontalMinMs: FRONTAL_MIN_MS, minFrames: 20, minMs: 2500 };
const labels = {
  FRONTAL: 'Lihat lurus ke depan',
  BLINK: 'Kedipkan kedua mata',
  TURN_LEFT: 'Menoleh sedikit ke kiri',
  TURN_RIGHT: 'Menoleh sedikit ke kanan',
};
const clients = { android: 'Android', web: 'Web' };
const photoLabels = {
  match: ['match', '✓ Cocok dengan foto SIMPEG'],
  mismatch: ['mismatch', 'Tidak cocok dengan foto SIMPEG'],
  no_photo: ['other', 'Belum ada foto di SIMPEG untuk dicocokkan'],
  unreadable: ['other', 'Foto SIMPEG tidak dapat dibaca untuk pencocokan'],
};

let stream = null, landmarker = null, busy = false, aborted = false, enrolled = false, lastPurpose = 'enroll', lastAksi = 'masuk', lastExtra = {};
let lastPhotoCheck = null, lastReviewMessage = '', simpegAvailable = false;
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
const coded = (message, code) => Object.assign(new Error(message), { code });
let lastFacePx = 0;   // lebar wajah terakhir (px kamera) — untuk catatan kegagalan

// Catat kegagalan di perangkat (kode alasan + jenis perangkat, tanpa gambar) agar admin tahu
// sebab kegagalan yang tidak sampai ke server wajah. Tidak ditunggu; gagal mencatat diabaikan.
function reportFailure(purpose, error) {
  try {
    const status = error?.status;
    const code = error?.code
      || (error?.kind === 'jaringan' ? 'koneksi' : error?.kind === 'ramai' ? 'server_ramai'
        : status === 422 ? 'ditolak_server' : status ? `server_${status}` : 'lain');
    fetch(`${root.dataset.api}?action=catat`, {
      method: 'POST', credentials: 'same-origin', keepalive: true,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf },
      body: JSON.stringify({ tujuan: purpose, kode: code, pesan: String(error?.message || '').slice(0, 160),
        mode: landmarkerDelegate || '', wajah_px: Math.round(lastFacePx) }),
    }).catch(() => {});
  } catch { /* abaikan */ }
}

// Mode diagnosis (?diag=1): satu baris info teknis di bawah kamera untuk dukungan jarak jauh.
// Hanya angka (mode, fps, sudut, nilai kedip) — tidak ada gambar/data wajah yang disimpan/dikirim.
const DIAG = new URLSearchParams(location.search).get('diag') === '1';
let diagLine = null;
function diag(text) {
  if (!DIAG) return;
  if (!diagLine) {
    diagLine = document.createElement('p');
    diagLine.style.cssText = 'margin:6px 0 0;font:12px/1.4 ui-monospace,monospace;color:#9fd8cf;text-align:center;word-break:break-word';
    stage.append(diagLine);
  }
  diagLine.textContent = text;
}

// ===================== Suara =====================
// Web Speech API (tanpa server). Dipicu pertama kali di dalam klik tombol agar
// diizinkan di Safari iPhone. Bisa dimatikan; pilihan disimpan di perangkat.
const voice = {
  supported: 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window,
  enabled: true,
  chosen: null,
  last: '',
  init() {
    try { this.enabled = localStorage.getItem('temanku_face_voice') !== 'off'; } catch { /* mode privat */ }
    if (!this.supported) { muteButton.hidden = true; return; }
    const pick = () => {
      const voices = speechSynthesis.getVoices();
      this.chosen = voices.find(v => /^id(-|_|$)/i.test(v.lang)) || voices.find(v => /indonesia/i.test(v.name)) || null;
    };
    pick();
    speechSynthesis.addEventListener?.('voiceschanged', pick);
    this.render();
  },
  prime() {
    if (!this.supported || !this.enabled) return;
    try { speechSynthesis.cancel(); speechSynthesis.speak(new SpeechSynthesisUtterance(' ')); } catch { /* abaikan */ }
  },
  say(text, force = false) {
    if (!this.supported || !this.enabled || !text) return;
    if (!force && text === this.last) return;
    this.last = text;
    try {
      speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(text);
      utterance.lang = 'id-ID';
      if (this.chosen) utterance.voice = this.chosen;
      utterance.rate = 1;
      speechSynthesis.speak(utterance);
    } catch { /* abaikan */ }
  },
  toggle() {
    this.enabled = !this.enabled;
    try { localStorage.setItem('temanku_face_voice', this.enabled ? 'on' : 'off'); } catch { /* abaikan */ }
    if (!this.enabled && this.supported) speechSynthesis.cancel();
    this.last = '';
    this.render();
  },
  render() {
    muteButton.textContent = this.enabled ? '🔊' : '🔇';
    muteButton.setAttribute('aria-pressed', String(!this.enabled));
    muteButton.setAttribute('aria-label', this.enabled ? 'Matikan suara' : 'Nyalakan suara');
  },
};

// ===================== API =====================
async function api(action, body) {
  const response = await fetch(`${root.dataset.api}?action=${encodeURIComponent(action)}`, {
    method: 'POST', credentials: 'same-origin', cache: 'no-store',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf, Accept: 'application/json' },
    body: typeof body === 'string' ? body : JSON.stringify(body),
  });
  const text = await response.text();
  let answer;
  try { answer = JSON.parse(text); } catch { throw new Error('Respons layanan tidak valid. Muat ulang halaman atau masuk kembali.'); }
  if (!response.ok) throw Object.assign(new Error(answer.error || 'Pemrosesan wajah belum berhasil'), { status: response.status });
  return answer;
}

// ===================== Halaman =====================
function staleCheck() {
  return simpegAvailable && (lastPhotoCheck === 'no_photo' || lastPhotoCheck === 'unreadable');
}
function refreshHints() {
  if (staleCheck()) {
    compareResult.hidden = false; compareResult.dataset.state = 'other';
    compareResult.textContent = 'Foto SIMPEG tersedia — ambil ulang untuk mencocokkan';
    review.hidden = false;
    review.textContent = 'Foto SIMPEG Anda sekarang sudah tersedia. Silakan ambil ulang wajah agar dicocokkan dengan foto tersebut.';
    return;
  }
  const label = photoLabels[lastPhotoCheck];
  compareResult.hidden = !label;
  if (label) { compareResult.dataset.state = label[0]; compareResult.textContent = label[1]; }
  review.hidden = !lastReviewMessage;
  review.textContent = lastReviewMessage;
}
function showSnapshot(jpeg) {
  snapshot.hidden = !jpeg; snapshotEmpty.hidden = Boolean(jpeg);
  snapshot.src = jpeg ? `data:image/jpeg;base64,${jpeg}` : '';
}

async function loadStatus() {
  try {
    const data = await api('status', {});
    enrolled = Boolean(data.enrolled && data.model_compatible);
    const via = clients[data.enrolled_via] ? ` · direkam lewat ${clients[data.enrolled_via]}` : '';
    if (enrolled) {
      statusTitle.textContent = 'Wajah sudah terdaftar';
      statusSub.textContent = `Aktif untuk Web dan Android${via}`;
      statusIcon.textContent = '✓';
      statusIcon.dataset.state = data.review_status === 'pending' ? 'warn' : 'ok';
    } else {
      statusTitle.textContent = 'Wajah belum direkam';
      statusSub.textContent = data.enrolled ? 'Rekaman lama tidak kompatibel. Silakan rekam ulang.' : 'Rekam wajah sekali untuk dipakai di Web dan Android.';
      statusIcon.textContent = '◎';
      statusIcon.dataset.state = '';
    }
    lastReviewMessage = enrolled ? (data.review_message || '') : '';
    lastPhotoCheck = enrolled ? (data.photo_check || null) : null;
    refreshHints();
    mainButton.textContent = enrolled ? 'Ambil ulang' : 'Rekam wajah';
    simulasiButton.hidden = !enrolled;
    if (noBlinkNote) noBlinkNote.hidden = !data.no_blink;
    if (openSimulasiOnLoad) {
      openSimulasiOnLoad = false;
      if (window.history?.replaceState) history.replaceState(null, '', window.location.pathname + window.location.search);
      if (enrolled) openChoose();
    }
  } catch (error) {
    statusSub.textContent = error.message;
  } finally {
    mainButton.disabled = busy;
  }
}

async function loadSimpegPhoto() {
  try {
    const data = await api('photo', {});
    if (data.photo_status === 'ok' && typeof data.photo === 'string' && /^data:image\/(jpeg|webp);base64,/.test(data.photo)) {
      simpegPhoto.src = data.photo; simpegPhoto.hidden = false; simpegEmpty.hidden = true;
      simpegAvailable = true; refreshHints();
    } else if (data.photo_status === 'unavailable' && root.dataset.foto) {
      // Server tidak dapat membaca file foto (mis. terpotong). Tampilkan file aslinya seperti
      // SIMPEG (browser memaafkan file tidak utuh) dan minta pegawai menyegarkan data.
      simpegPhoto.onload = () => { simpegPhoto.hidden = false; simpegEmpty.hidden = true; };
      simpegPhoto.onerror = () => { simpegEmpty.textContent = 'Foto SIMPEG tidak dapat dimuat'; };
      simpegPhoto.src = `${root.dataset.foto}?v=${Date.now()}`;
      const caption = simpegPhoto.parentElement?.querySelector('figcaption');
      if (caption) caption.textContent = 'Foto SIMPEG — file tidak utuh, lakukan Segarkan data';
    } else {
      simpegEmpty.textContent = data.photo_status === 'no_photo' ? 'Belum ada foto di SIMPEG' : 'Foto SIMPEG tidak dapat dimuat';
    }
  } catch { simpegEmpty.textContent = 'Foto SIMPEG tidak dapat dimuat'; }
}

// ===================== Popup =====================
function setRing(fraction) {
  ring.style.strokeDashoffset = String(RING_LENGTH * (1 - Math.max(0, Math.min(1, fraction))));
}
function setCue(target) {
  arrowLeft.hidden = target !== 'TURN_LEFT';     // pratinjau dicerminkan: kiri pegawai = kiri layar
  arrowRight.hidden = target !== 'TURN_RIGHT';
  blinkIcon.hidden = target !== 'BLINK';
}
function say(text, note = '', speak = true) {
  instruction.textContent = text;
  hint.textContent = note;
  if (speak) voice.say(text);
}
function openModal(purpose) {
  lastPurpose = purpose;
  aborted = false;
  modal.dataset.mode = purpose;
  choosePanel.hidden = true;
  stage.hidden = false;
  checksList.hidden = true;
  checksList.replaceChildren();
  modal.dataset.state = 'loading';
  delete modal.dataset.result;
  delete modal.dataset.warn;
  resultIcon.textContent = '';
  actions.hidden = true;
  retryButton.hidden = true;
  setRing(0); setCue(null);
  say('Menyiapkan kamera…', '', false);
  modal.hidden = false;
  document.documentElement.style.overflow = 'hidden';
}
function closeModal() {
  if (modal.dataset.state === 'processing') return;  // jangan putus saat server memproses
  aborted = true;
  stopCamera();
  if (voice.supported) speechSynthesis.cancel();
  modal.hidden = true;
  document.documentElement.style.overflow = '';
  mainButton.focus();
}
function showResult(success, title, note) {
  stopCamera();
  modal.dataset.state = 'result';
  modal.dataset.result = success ? 'ok' : 'fail';
  delete modal.dataset.warn;
  setCue(null);
  resultIcon.textContent = success ? '✓' : '!';
  if (success) setRing(1);
  say(title, note, false);
  voice.say(title, true);
  actions.hidden = false;
  retryButton.hidden = success;
  doneButton.textContent = success ? 'Selesai' : 'Tutup';
  (success ? doneButton : retryButton).focus();
}

// ===================== Upload tahan sinyal lemah =====================
// XHR agar progres upload terlihat. Kiriman ulang aman: server mengingat hasil tiap
// tantangan, jadi bila jawaban sempat hilang, kiriman ulang mendapat hasil yang sama.
function uploadOnce(action, payload, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', `${root.dataset.api}?action=${encodeURIComponent(action)}`);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.setRequestHeader('X-CSRF-Token', root.dataset.csrf);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.withCredentials = true;
    xhr.timeout = 120000;
    xhr.upload.onprogress = event => { if (event.lengthComputable) onProgress(event.loaded / event.total); };
    // kind: 'jaringan' (sinyal), 'ramai' (server penuh), 'server' (error server, dicoba sebentar), 'tetap' (tidak dicoba ulang)
    const fail = (message, kind, status = 0) => reject(Object.assign(new Error(message), { kind, status }));
    xhr.onload = () => {
      let answer;
      try { answer = JSON.parse(xhr.responseText); } catch {
        fail(`Server menjawab tidak valid (kode ${xhr.status})`, xhr.status === 0 ? 'jaringan' : xhr.status >= 500 ? 'server' : 'tetap', xhr.status); return;
      }
      if (xhr.status >= 200 && xhr.status < 300) { resolve(answer); return; }
      const message = answer.error || 'Pemrosesan wajah belum berhasil';
      if (xhr.status === 429) fail(message, 'ramai', 429);
      // 502 dari server web: Go tidak terjangkau (upstream 0) dicoba ulang; rute belum ada (404) tidak.
      else if (xhr.status === 502) fail(message, answer.upstream === 0 ? 'server' : 'tetap', 502);
      else if (xhr.status === 503 || xhr.status === 504) fail(message, 'server', xhr.status);
      else fail(message, 'tetap', xhr.status);
    };
    xhr.onerror = () => fail('Koneksi terputus', 'jaringan');
    xhr.ontimeout = () => fail('Koneksi terlalu lambat', 'jaringan');
    xhr.send(payload);
  });
}
function waitForSignal(limitMs) {
  if (navigator.onLine !== false) return Promise.resolve();
  hint.textContent = 'Tidak ada sinyal. Menunggu sinyal…';
  return new Promise(resolve => {
    const done = () => { window.removeEventListener('online', done); clearTimeout(timer); resolve(); };
    const timer = setTimeout(done, Math.max(0, limitMs));
    window.addEventListener('online', done);
  });
}
async function sendWithRetry(action, payload, deadline) {
  // Sinyal lemah: sampai 5x. Error server: 2x saja, lalu tampilkan kodenya.
  const pauses = { jaringan: [2000, 4000, 8000, 15000, 25000], ramai: [3000, 6000, 10000, 15000], server: [3000, 8000] };
  for (let attempt = 0; ; attempt++) {
    await waitForSignal(deadline - Date.now());
    try {
      return await uploadOnce(action, payload, part => {
        hint.textContent = part < 1 ? `Mengirim… ${Math.round(part * 100)}%${attempt ? ` (percobaan ${attempt + 1})` : ''}` : 'Memeriksa di server…';
      });
    } catch (error) {
      const pause = (pauses[error.kind] || [])[attempt];
      if (aborted || pause === undefined || Date.now() + pause > deadline) {
        if (error.status && !/kode \d+/.test(error.message)) error.message += ` (kode ${error.status})`;
        throw error;
      }
      hint.textContent = error.kind === 'jaringan' ? `Sinyal lemah, mencoba lagi dalam ${pause / 1000} detik…`
        : error.kind === 'ramai' ? `Server sedang ramai, mencoba lagi dalam ${pause / 1000} detik…`
        : `Server belum menjawab (kode ${error.status}), mencoba lagi dalam ${pause / 1000} detik…`;
      await wait(pause);
    }
  }
}

// ===================== Simulasi absensi =====================
// GPS browser adalah klaim lokasi (bukan bukti anti-pemalsuan); server menilainya
// terhadap titik presensi APEL. Diminta bersamaan dengan kamera.
function getLocation() {
  return new Promise(resolve => {
    if (!navigator.geolocation) { resolve({ error: 'Perangkat tidak mendukung GPS' }); return; }
    navigator.geolocation.getCurrentPosition(
      position => resolve({ location: { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy_m: position.coords.accuracy || 9999 } }),
      error => resolve({ error: error.code === 1 ? 'Izin lokasi ditolak. Izinkan lokasi untuk situs ini, lalu coba lagi.' : error.code === 3 ? 'Lokasi GPS tidak didapat (waktu habis). Coba di area terbuka.' : 'Lokasi GPS tidak tersedia' }),
      { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  });
}
// Mode simulasi dari config.php (FACE_SIMULASI_LENGKAP). Bawaan 'wajah': pegawai tetap memilih
// masuk/pulang, tetapi yang dinilai HANYA wajah — tanpa lokasi, waktu, atau cek absen masuk.
const SIMULASI_LENGKAP = root.dataset.simulasi === 'lengkap';
function startSimulasi() { openChoose(); }
// Dibuka dari menu "Presensi & Kehadiran" (alamat .../profil/wajah#simulasi): pilihan masuk/pulang
// langsung tampil bila wajah sudah terdaftar. Bila belum, halaman perekaman tampil seperti biasa.
let openSimulasiOnLoad = window.location.hash === '#simulasi';
function openChoose() {
  if (busy) return;
  openModal('simulasi');
  stage.hidden = true;
  choosePanel.hidden = false;
  modal.dataset.state = 'choose';
  $('#face-choose-masuk').focus();
}
function showChecks(checks) {
  checksList.replaceChildren();
  for (const check of checks) {
    const item = document.createElement('li');
    item.className = check.status;
    const icon = document.createElement('span');
    icon.className = 'ikon';
    icon.textContent = check.status === 'ok' ? '✓' : check.status === 'catatan' ? '!' : check.status === 'lewati' ? '–' : '✗';
    const text = document.createElement('span');
    const label = document.createElement('strong');
    label.textContent = check.label;
    const note = document.createElement('small');
    note.textContent = check.message;
    text.append(label, note);
    item.append(icon, text);
    checksList.append(item);
  }
  checksList.hidden = false;
}

// ===================== Kamera & deteksi =====================
function stopCamera() {
  if (stream) for (const track of stream.getTracks()) track.stop();
  stream = null; video.srcObject = null;
}
function cameraError(error) {
  const iphone = /iPhone|iPad|iPod/.test(navigator.userAgent);
  switch (error?.name) {
    case 'NotAllowedError':
    case 'SecurityError':
      return iphone
        ? 'Akses kamera ditolak. Ketuk "aA" di bilah alamat › Pengaturan Situs Web › Kamera › Izinkan, lalu muat ulang halaman.'
        : 'Akses kamera ditolak. Klik ikon gembok/kamera di bilah alamat, izinkan kamera, lalu muat ulang halaman.';
    case 'NotFoundError':
    case 'OverconstrainedError':
      return 'Kamera depan tidak ditemukan pada perangkat ini.';
    case 'NotReadableError':
    case 'AbortError':
      return 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi kamera/video call lalu coba lagi.';
    default:
      return error?.message || 'Kamera belum dapat diaktifkan.';
  }
}
async function openCamera() {
  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) throw new Error('Kamera memerlukan HTTPS dan browser yang mendukung kamera.');
  stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false });
  video.srcObject = stream;
  await video.play();
}
let landmarkerTask = null;
let landmarkerDelegate = null;   // 'GPU' | 'CPU' yang sedang dipakai
let vision = null;               // { module, files } untuk membuat ulang pendeteksi
// Sebagian HP Android: mode GPU berjalan tanpa error tetapi TIDAK PERNAH menemukan wajah.
// Bila itu terjadi, pendeteksi pindah ke CPU dan pilihan diingat di perangkat ini
// (hanya nama mode, bukan data wajah).
const DELEGATE_KEY = 'temanku_face_delegate';
function preferredDelegate() {
  try { return localStorage.getItem(DELEGATE_KEY) === 'CPU' ? 'CPU' : 'GPU'; } catch { return 'GPU'; }
}
function rememberCpu() { try { localStorage.setItem(DELEGATE_KEY, 'CPU'); } catch { /* mode privat */ } }
function landmarkerOptions(delegate) {
  return {
    baseOptions: { modelAssetPath: `${root.dataset.assets}/face_landmarker.task`, delegate },
    runningMode: 'VIDEO', numFaces: 2, outputFaceBlendshapes: true, outputFacialTransformationMatrixes: true,
  };
}
async function loadLandmarker() {
  if (landmarker) return;
  if (!modal.hidden) say('Memuat pendeteksi wajah…', 'Pertama kali bisa 10–30 detik', false);
  // Satu jalur unduh: pemuatan di latar belakang dan klik tombol memakai janji yang sama.
  landmarkerTask ||= (async () => {
    const module = await import(`${root.dataset.assets}/vision_bundle.mjs`);
    const files = await module.FilesetResolver.forVisionTasks(`${root.dataset.assets}/wasm`);
    vision = { module, files };
    if (preferredDelegate() === 'GPU') {
      // GPU (WebGL) jauh lebih cepat sehingga kedipan singkat tidak terlewat.
      try { const made = await module.FaceLandmarker.createFromOptions(files, landmarkerOptions('GPU')); landmarkerDelegate = 'GPU'; return made; }
      catch { rememberCpu(); }
    }
    const made = await module.FaceLandmarker.createFromOptions(files, landmarkerOptions('CPU'));
    landmarkerDelegate = 'CPU';
    return made;
  })();
  try {
    landmarker = await landmarkerTask;
  } catch (error) {
    landmarkerTask = null;
    throw coded(`Pendeteksi wajah gagal dimuat (${error?.message || error}). Periksa koneksi lalu coba lagi.`, 'pendeteksi_gagal');
  }
}
async function switchToCpu() {
  if (landmarkerDelegate === 'CPU' || !vision) return false;
  const made = await vision.module.FaceLandmarker.createFromOptions(vision.files, landmarkerOptions('CPU'));
  try { landmarker?.close?.(); } catch { /* abaikan */ }
  landmarker = made; landmarkerDelegate = 'CPU'; landmarkerTask = Promise.resolve(made);
  rememberCpu();
  return true;
}

// Muat pendeteksi diam-diam setelah halaman tenang, agar kamera langsung siap saat diklik.
// Tidak dilakukan bila pengguna mengaktifkan mode hemat data.
function prefetchDetector() {
  if (landmarker || landmarkerTask || navigator.connection?.saveData) return;
  loadLandmarker().catch(() => {});
}

// Besar sudut dari matriks MediaPipe (data JS column-major: R[2][0]=m[2], R[0][0]=m[0], R[1][0]=m[1]).
// Arah dari geometri: pada frame TIDAK dicerminkan, menoleh ke kiri pegawai membuat
// ujung hidung bergeser ke kanan gambar relatif titik tengah kedua mata.
// Positif = kiri pegawai. Rumus identik dengan server (geometry.signed_yaw).
function signedYaw(landmarks, matrix) {
  const magnitude = Math.abs(Math.atan2(-matrix[2], Math.hypot(matrix[0], matrix[1])) * 180 / Math.PI);
  const w = video.videoWidth, h = video.videoHeight;
  const point = i => [landmarks[i].x * w, landmarks[i].y * h];
  const middle = (a, b) => [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];
  const eyeA = middle(point(33), point(133)), eyeB = middle(point(362), point(263)), nose = point(1);
  const distance = Math.hypot(eyeA[0] - eyeB[0], eyeA[1] - eyeB[1]);
  if (!(distance > 1e-6) || !Number.isFinite(magnitude)) return null;
  const offset = (nose[0] - (eyeA[0] + eyeB[0]) / 2) / distance;
  if (magnitude >= TURN_MIN && Math.abs(offset) < 0.03) return AMBIGUOUS;
  return offset >= 0 ? magnitude : -magnitude;
}
function pose(yaw) {
  if (Math.abs(yaw) <= FRONTAL_MAX) return 'FRONTAL';
  if (yaw >= TURN_MIN && yaw <= TURN_MAX) return 'TURN_LEFT';
  if (yaw <= -TURN_MIN && yaw >= -TURN_MAX) return 'TURN_RIGHT';
  return 'OTHER';
}
function captureJpeg(canvas, context, quality = 0.8) {
  context.drawImage(video, 0, 0, canvas.width, canvas.height); // tanpa mirror
  return canvas.toDataURL('image/jpeg', quality).split(',')[1];
}

// Ikuti urutan gerakan dari server. Mengembalikan frame + cuplikan frontal.
async function capture(actionsList, quick = false, oneEye = false) {
  // Mode tanpa kedip (akomodasi admin): cukup satu mata terbuka. SAMA dengan server (liveness.eyes_usable).
  const eyesOk = (l, r) => (oneEye ? Math.min(l, r) < 0.4 : l < 0.4 && r < 0.4);
  const rules = quick ? QUICK : FULL;
  const frames = [];
  let cursor = 0, consecutive = 0, closed = false, frontal = null, lastTime = -1, lastWarnSpoken = 0;
  // Perekaman: semua frame 640 px. Verifikasi: frame kecil 320 px (q0,6) untuk gerakan + 2 frame
  // kunci 480 px (q0,8; lurus, sebelum & sesudah gerakan) untuk pencocokan & anti-spoofing.
  // Ukuran diukur: ±250 KB per verifikasi (sebelumnya ±800 KB). Pengenal wajah memakai
  // 112x112 px dan anti-spoofing 80x80 px, jadi 480 px sudah lebih dari cukup.
  const makeCanvas = size => {
    const c = document.createElement('canvas');
    const k = Math.min(1, size / Math.max(video.videoWidth, video.videoHeight));
    c.width = Math.round(video.videoWidth * k); c.height = Math.round(video.videoHeight * k);
    return [c, c.getContext('2d')];
  };
  const [canvas, context] = makeCanvas(640);
  // Ukuran frame verifikasi menyesuaikan besar wajah: HP tegak/dipegang jauh membuat wajah kecil,
  // jadi frame diperbesar agar lebar wajah >= ±110 px (gerakan) / ±140 px (kunci). Server menolak
  // wajah < 48 px (gerakan) / < 80 px (kunci). Wajah besar tetap memakai frame kecil yang hemat.
  const sizes = {};
  const sized = side => (sizes[side] ||= makeCanvas(side));
  const maxSide = Math.max(video.videoWidth, video.videoHeight);
  let faceNative = 0;   // lebar wajah (px) pada resolusi kamera asli
  const pick = (targetPx, minSide) => {
    for (const side of [320, 400, 480, 640]) {
      if (side < minSide) continue;
      if (!faceNative || faceNative * Math.min(1, side / maxSide) >= targetPx) return sized(side);
    }
    return sized(640);
  };
  const keys = { first: false, last: false };
  modal.dataset.state = 'capture';
  const start = performance.now();
  let shown = null, shownAt = 0, blinkHintSpoken = false, noFace = 0, faceSeen = false;
  let diagFrames = 0, diagSince = performance.now(), diagFps = 0;
  while (!aborted && cursor < actionsList.length && performance.now() - start < CAPTURE_MAX_MS && frames.length < MAX_FRAMES) {
    const tick = performance.now(); const moment = Math.round(tick - start);
    if (moment <= lastTime) { await wait(10); continue; }
    lastTime = moment;
    const target = actionsList[cursor];
    if (shown !== cursor) { shown = cursor; shownAt = tick; blinkHintSpoken = false; setCue(target); say(labels[target]); }
    let detected;
    try { detected = landmarker.detectForVideo(video, tick); }
    catch (error) {
      // GPU gagal di tengah jalan (mis. konteks WebGL hilang): pindah ke CPU dan lanjut.
      if (landmarkerDelegate === 'GPU' && await switchToCpu()) { lastTime = -1; continue; }
      throw error;
    }
    if (detected.faceLandmarks.length === 0) {
      noFace++;
      // ±2 dtk tanpa satu pun wajah di mode GPU: anggap GPU tidak cocok untuk HP ini.
      if (!faceSeen && noFace >= 16 && landmarkerDelegate === 'GPU') {
        hint.textContent = 'Menyesuaikan pendeteksi untuk perangkat ini…';
        if (await switchToCpu()) { noFace = 0; lastTime = -1; continue; }
      }
    } else { faceSeen = true; noFace = 0; }
    if (DIAG) {
      diagFrames++;
      if (tick - diagSince >= 1000) { diagFps = Math.round(diagFrames * 1000 / (tick - diagSince)); diagFrames = 0; diagSince = tick; }
      const m = detected.facialTransformationMatrixes?.[0]?.data;
      const y = m && detected.faceLandmarks.length === 1 ? signedYaw(detected.faceLandmarks[0], m) : null;
      const b = Object.fromEntries((detected.faceBlendshapes?.[0]?.categories || []).map(c => [c.categoryName, c.score]));
      diag(`${landmarkerDelegate || '?'} · ${diagFps} fps · ${video.videoWidth}x${video.videoHeight} · wajah ${detected.faceLandmarks.length} (${Math.round(faceNative)}px)`
        + ` · yaw ${y === null ? '-' : y.toFixed(1)} · kedip ${(b.eyeBlinkLeft ?? 0).toFixed(2)}/${(b.eyeBlinkRight ?? 0).toFixed(2)}`
        + ` · langkah ${cursor + 1}/${actionsList.length} ${actionsList[cursor] || ''}`);
    }
    if (detected.faceLandmarks.length !== 1) {
      modal.dataset.warn = '1';
      hint.textContent = detected.faceLandmarks.length > 1 ? 'Pastikan hanya satu wajah terlihat'
        : noFace > 40 ? 'Wajah belum terdeteksi. Pastikan cahaya cukup, wajah di dalam lingkaran, dan lensa kamera bersih.'
        : 'Posisikan wajah di dalam lingkaran';
      if (tick - lastWarnSpoken > 6000) { voice.say(hint.textContent, true); lastWarnSpoken = tick; voice.last = ''; }
      await wait(FRAME_INTERVAL_MS); continue;
    }
    delete modal.dataset.warn;
    const matrix = detected.facialTransformationMatrixes[0]?.data;
    const yaw = matrix ? signedYaw(detected.faceLandmarks[0], matrix) : null;
    const scores = Object.fromEntries((detected.faceBlendshapes[0]?.categories || []).map(item => [item.categoryName, item.score]));
    const openNow = eyesOk(scores.eyeBlinkLeft ?? 0, scores.eyeBlinkRight ?? 0);
    const frontalNow = yaw !== null && pose(yaw) === 'FRONTAL' && openNow;
    // Frame kunci: wajah lurus & mata terbuka pada langkah lurus pertama dan terakhir.
    if (detected.faceLandmarks.length === 1) {
      let lo = 1, hi = 0;
      for (const point of detected.faceLandmarks[0]) { if (point.x < lo) lo = point.x; if (point.x > hi) hi = point.x; }
      faceNative = Math.max(0, hi - lo) * video.videoWidth;
      lastFacePx = faceNative;
    }
    const slot = quick && target === 'FRONTAL' && frontalNow
      ? (cursor === 0 && !keys.first ? 'first' : cursor === actionsList.length - 1 && !keys.last ? 'last' : null) : null;
    const [frameCanvas, frameContext] = !quick ? [canvas, context] : slot ? pick(140, 480) : pick(110, 320);
    const jpeg = captureJpeg(frameCanvas, frameContext, !quick ? 0.8 : slot ? 0.8 : 0.6);
    if (jpeg.length > 280000) throw coded('Ukuran frame terlalu besar. Coba pencahayaan yang lebih merata.', 'frame_terlalu_besar');
    if (slot) { keys[slot] = true; frames.push({ t_ms: moment, jpeg, key: true }); } else frames.push({ t_ms: moment, jpeg });
    let partial = 0, note = '';
    // Menunduk/mendongak (HP di bawah dagu / terlalu tinggi) mengurangi ketepatan pengenalan:
    // hanya petunjuk, keputusan tetap di server.
    const pitch = matrix ? Math.abs(Math.atan2(matrix[6], matrix[10]) * 180 / Math.PI) : 0;
    if (target === 'FRONTAL' && pitch > 20) note = 'Sejajarkan HP dengan mata — jangan terlalu menunduk atau mendongak';
    if (yaw !== null) {
      const left = scores.eyeBlinkLeft ?? 0, right = scores.eyeBlinkRight ?? 0;
      const current = pose(yaw);
      if (frontalNow && (slot || !quick)) frontal = jpeg;
      if (target === 'BLINK') {
        if (current === 'FRONTAL') {
          if (eyesClosed(left, right)) closed = true;
          else if (closed && eyesOpen(left, right)) { cursor++; closed = false; consecutive = 0; }
          partial = closed ? 0.5 : 0;
          if (!closed && tick - shownAt > 3000) {
            note = 'Pejamkan mata sebentar, lalu buka';
            if (!blinkHintSpoken) { voice.say(note, true); blinkHintSpoken = true; }
          }
        } else note = 'Hadapkan wajah lurus, lalu kedipkan mata';
      } else {
        consecutive = current === target && eyesOk(left, right) ? consecutive + 1 : 0;
        const dwell = target !== 'FRONTAL' || tick - shownAt >= rules.frontalMinMs;
        if (consecutive >= 2 && dwell) { cursor++; consecutive = 0; closed = false; }
        partial = Math.min(consecutive / 2, 0.9);
        if (current === 'OTHER' && Math.abs(yaw) > TURN_MAX) note = 'Terlalu jauh, kembali sedikit';
      }
    }
    hint.textContent = note;
    setRing((cursor + partial) / actionsList.length);
    // Rapat hanya di awal langkah kedip dan selama kuota frame masih longgar
    // (server menerima maksimal 200 frame per rekaman).
    const fast = actionsList[cursor] === 'BLINK' && performance.now() - shownAt < 4000 && frames.length < 140;
    const interval = fast ? BLINK_INTERVAL_MS : FRAME_INTERVAL_MS;
    await wait(Math.max(5, interval - (performance.now() - tick)));
  }
  if (aborted) throw new Error('Dibatalkan');
  if (cursor !== actionsList.length) throw coded('Gerakan belum selesai dalam 30 detik. Silakan coba lagi dan ikuti petunjuknya.', faceSeen ? 'gerakan_waktu_habis' : 'wajah_tak_terdeteksi');
  // Server butuh jumlah frame & durasi klip minimum (lebih kecil untuk verifikasi).
  if (frames.length < rules.minFrames || performance.now() - start < rules.minMs) { setCue(null); say('Tahan sebentar', '', true); }
  while ((frames.length < rules.minFrames || performance.now() - start < rules.minMs) && performance.now() - start < CAPTURE_MAX_MS && frames.length < MAX_FRAMES) {
    // Hanya frame yang memang berisi satu wajah (frame kosong tidak dikirim).
    const tick = performance.now();
    let one = true;
    try { one = landmarker.detectForVideo(video, tick).faceLandmarks.length === 1; } catch { one = true; }
    if (one) {
      const [holdCanvas, holdContext] = quick ? pick(110, 320) : [canvas, context];
      frames.push({ t_ms: Math.round(tick - start), jpeg: captureJpeg(holdCanvas, holdContext, quick ? 0.6 : 0.8) });
    }
    await wait(FRAME_INTERVAL_MS);
  }
  return { frames, frontal };
}

// ===================== Alur satu tombol =====================
async function run(purpose, aksi = 'masuk', extra = {}) {
  if (busy) return;
  busy = true; mainButton.disabled = true;
  lastAksi = aksi;
  lastExtra = extra;
  openModal(purpose);
  voice.prime();                       // harus di dalam klik pengguna (Safari iPhone)
  const locationTask = (purpose === 'simulasi' && SIMULASI_LENGKAP) || purpose === 'absen' ? getLocation() : null;
  const recording = purpose === 'enroll';
  const askChallenge = () => api(recording ? 'enroll/start' : 'challenge',
    recording ? { consent: true } : { purpose: 'verify' });   // simulasi = verifikasi wajah
  // Bila pendeteksi sudah dimuat, tantangan diminta bersamaan dengan menyalakan kamera.
  let challengeTask = landmarker ? askChallenge() : null;
  challengeTask?.catch(() => {});
  try {
    try { await openCamera(); } catch (error) {
      const name = error?.name;
      throw coded(cameraError(error), name === 'NotAllowedError' || name === 'SecurityError' ? 'kamera_ditolak'
        : name === 'NotFoundError' || name === 'OverconstrainedError' ? 'kamera_tidak_ada'
        : name === 'NotReadableError' || name === 'AbortError' ? 'kamera_dipakai' : 'kamera_lain');
    }
    if (aborted) return;
    await loadLandmarker();
    if (aborted) return;
    say('Posisikan wajah di dalam lingkaran', '', recording);
    const challenge = await (challengeTask || askChallenge());
    const deadline = Date.now() + Math.max(30, (Number(challenge.expires_in) || 60) - 10) * 1000;
    if (aborted) return;
    const { frames, frontal } = await capture(challenge.actions, !recording, Boolean(challenge.no_blink));
    modal.dataset.state = 'processing';
    stopCamera();
    say('Memproses…', 'Jangan tutup halaman ini', false);
    voice.say('Sedang memproses', true);
    const request = { challenge_id: challenge.challenge_id, nonce: challenge.nonce, frames };
    if (purpose === 'absen') Object.assign(request, { pola: extra.pola || '', tanggal_dinas: extra.tanggal_dinas || '' });   // dari tombol yang ditekan
    if (locationTask) {
      hint.textContent = 'Memeriksa lokasi…';
      const place = await locationTask;
      if (place.location) request.location = place.location; else request.location_error = place.error;
    }
    const payload = JSON.stringify(request);
    frames.length = 0;
    if (payload.length > 20 * 1024 * 1024) throw new Error('Perekaman terlalu besar. Silakan ulang.');
    const endpoint = recording ? 'enroll/complete' : purpose === 'simulasi' ? `simulasi/${aksi}` : purpose === 'absen' ? `absen/${aksi}` : 'verify';
    const answer = await sendWithRetry(endpoint, payload, deadline);
    if (purpose === 'absen') {
      const ok = Boolean(answer.tercatat);
      const what = extra.label || `Absen ${absenLabels[aksi] || aksi}`;
      showResult(ok, ok ? `${what} — berhasil` : `${what} — belum berhasil`, `${answer.waktu || ''}. ${answer.pesan || ''}`);
      showChecks(Array.isArray(answer.checks) ? answer.checks : []);
      retryButton.hidden = ok;
      loadAbsen();
    } else if (purpose === 'simulasi') {
      const faceOnly = answer.mode === 'wajah';
      showResult(Boolean(answer.akan_berhasil),
        faceOnly ? (answer.akan_berhasil ? `Simulasi absen ${aksi} berhasil` : `Simulasi absen ${aksi} belum berhasil`)
          : answer.akan_berhasil ? `Simulasi absen ${aksi} berhasil` : `Simulasi absen ${aksi} belum memenuhi syarat`,
        faceOnly ? (answer.kesimpulan || '') : `${answer.waktu || ''}. ${answer.kesimpulan || ''}`);
      showChecks(Array.isArray(answer.checks) ? answer.checks : []);
      retryButton.hidden = false;
    } else if (purpose === 'enroll') {
      showSnapshot(frontal);
      // Belum ada foto SIMPEG (mis. BKN tidak terhubung): cuplikan wajah depan dipakai sebagai foto sementara.
      if (root.dataset.fotoSimpeg === '0' && frontal) api('foto-sementara', { jpeg: frontal }).catch(() => {});
      const match = answer.photo_check === 'match';
      showResult(true, 'Wajah berhasil direkam',
        match ? 'Cocok dengan foto SIMPEG. Wajah aktif untuk Web dan Android.' : (answer.review_message || answer.message || ''));
    } else {
      showResult(Boolean(answer.matched), answer.matched ? 'Wajah cocok' : 'Wajah belum cocok',
        answer.matched ? 'Verifikasi berhasil.' : 'Coba lagi dengan pencahayaan lebih baik dan wajah lurus ke kamera.');
    }
    await loadStatus();
  } catch (error) {
    diag(`galat: ${error?.code || error?.name || ''} ${error?.message || error}`);
    console.error('TEMANKU wajah:', error);
    if (!aborted) {
      reportFailure(purpose, error);
      showResult(false, 'Belum berhasil', error.message || 'Terjadi kesalahan. Silakan coba lagi.');
    }
  } finally {
    busy = false; mainButton.disabled = false;
    if (purpose === 'absen') loadAbsen();   // perbarui catatan & tombol setelah kamera selesai
  }
}

// ===================== Kejadian =====================
mainButton.addEventListener('click', () => run('enroll'));
simulasiButton.addEventListener('click', startSimulasi);
$('#face-choose-masuk').addEventListener('click', () => run('simulasi', 'masuk'));
$('#face-choose-pulang').addEventListener('click', () => run('simulasi', 'pulang'));
retryButton.addEventListener('click', () => {
  closeModal();
  if (lastPurpose === 'absen-lokasi') absenTanpaWajah(lastAksi, lastExtra);
  else run(lastPurpose, lastAksi, lastExtra);
});
doneButton.addEventListener('click', closeModal);
closeButton.addEventListener('click', closeModal);
muteButton.addEventListener('click', () => voice.toggle());
document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) closeModal(); });
window.addEventListener('pagehide', () => { aborted = true; stopCamera(); });

// ===================== Absen (absen.php) =====================
// Metode absensi unit (dari server): 'WAJAH' = lokasi + wajah; 'LOKASI' = lokasi saja (tanpa kamera).
let absenMetode = 'LOKASI';
function requestId() {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return 'lokasi-' + Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
}
// Absen unit bermetode LOKASI: tanpa kamera; jadwal & lokasi tetap diputuskan server.
async function absenTanpaWajah(aksi, extra = {}) {
  if (busy) return;
  busy = true;
  lastAksi = aksi;
  lastExtra = extra;
  openModal('absen');
  lastPurpose = 'absen-lokasi';
  say('Memeriksa lokasi & mencatat absen…', '', false);
  try {
    const gps = await getLocation();
    const answer = await api(`absen/${aksi}`, Object.assign({ challenge_id: requestId(), nonce: '', frames: [],
      pola: extra.pola || '', tanggal_dinas: extra.tanggal_dinas || '' }, gps.location ? { location: gps.location } : { location_error: gps.error || 'Lokasi tidak tersedia' }));
    const ok = Boolean(answer.tercatat);
    const what = extra.label || `Absen ${absenLabels[aksi] || aksi}`;
    showResult(ok, ok ? `${what} — berhasil` : `${what} — belum berhasil`, `${answer.waktu || ''}. ${answer.pesan || ''}`);
    showChecks(Array.isArray(answer.checks) ? answer.checks : []);
    retryButton.hidden = ok;
  } catch (error) {
    showResult(false, 'Belum berhasil', error.message || 'Terjadi kesalahan. Silakan coba lagi.');
  } finally {
    busy = false;
    loadAbsen();
  }
}

// ---- Peta & pembaruan otomatis halaman absen ----
const ABSEN_MODE = root.dataset.absenMode === 'upacara' ? 'upacara' : 'umum';
let peta = null, petaArea = null, petaUser = null, petaAkurasi = null, petaFit = false;
let posisiTerakhir = null, posisiTerkirim = null, statusTerakhir = 0, absenMemuat = false, pantauGPS = null;

function jarakMeter(a, b) {
  const r = 6371000, rad = x => x * Math.PI / 180;
  const dLat = rad(b.latitude - a.latitude), dLng = rad(b.longitude - a.longitude);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLng / 2) ** 2;
  return 2 * r * Math.asin(Math.sqrt(h));
}

function petaGagal(error) {
  const el = $('#absen-peta');
  if (el) el.hidden = true;
  if (window.console) console.warn('Peta absen tidak dapat ditampilkan', error);
}

function gambarPosisi(location) {
  if (!peta || !location) return;
  const ll = [location.latitude, location.longitude];
  if (!petaUser) {
    petaAkurasi = L.circle(ll, { radius: location.accuracy_m || 30, color: '#2f5fa8', weight: 1, fillOpacity: 0.08 }).addTo(peta);
    petaUser = L.circleMarker(ll, { radius: 7, color: '#fff', weight: 2, fillColor: '#2f5fa8', fillOpacity: 1 }).addTo(peta).bindTooltip('Posisi Anda');
  } else {
    petaUser.setLatLng(ll);
    petaAkurasi.setLatLng(ll).setRadius(location.accuracy_m || 30);
  }
}

function gambarPeta(data, location) {
  const el = $('#absen-peta');
  if (!el) return;
  if (typeof L === 'undefined') { el.hidden = true; return; }
  if (!peta) {
    peta = L.map(el, { zoomControl: true, attributionControl: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(peta);
    petaArea = L.layerGroup().addTo(peta);
    peta.setView([-6.1206, 120.4665], 13);
  }
  petaArea.clearLayers();
  const bounds = [];
  for (const a of data.peta?.area || []) {
    const color = { kantor: '#168f90', upacara: '#b0451f', dinas: '#6b4fa0' }[a.jenis] || '#168f90';
    const layer = a.polygon?.length >= 3
      ? L.polygon(a.polygon.map(p => [p.lat, p.lng]), { color, weight: 2, fillOpacity: 0.12 })
      : L.circle([a.center.lat, a.center.lng], { radius: a.radius || 50, color, weight: 2, fillOpacity: 0.12 });
    layer.addTo(petaArea).bindTooltip(a.nama || a.jenis);
    bounds.push(layer.getBounds());
  }
  gambarPosisi(location);
  if (!petaFit) {   // sekali di awal: tampilkan area & posisi; selanjutnya pengguna bebas menggeser peta
    let all = null;
    for (const b of bounds) all = all ? all.extend(b) : L.latLngBounds(b.getSouthWest(), b.getNorthEast());
    if (location) all = all ? all.extend([location.latitude, location.longitude]) : L.latLngBounds([[location.latitude, location.longitude], [location.latitude, location.longitude]]);
    if (all) { peta.fitBounds(all.pad(0.25), { maxZoom: 17 }); petaFit = true; }
  }
  setTimeout(() => peta.invalidateSize(), 150);
}

// GPS dipantau terus: bila bergerak > 10 m (atau tiap 30 detik) status diperbarui otomatis.
function mulaiPantauGPS() {
  if (pantauGPS !== null || !navigator.geolocation?.watchPosition) return;
  pantauGPS = navigator.geolocation.watchPosition(pos => {
    posisiTerakhir = { location: { latitude: pos.coords.latitude, longitude: pos.coords.longitude, accuracy_m: pos.coords.accuracy }, waktu: Date.now() };
    try { gambarPosisi(posisiTerakhir.location); } catch (e) { petaGagal(e); }
    const bergerak = !posisiTerkirim || jarakMeter(posisiTerkirim, posisiTerakhir.location) > 10;
    if (bergerak && Date.now() - statusTerakhir > 8000) loadAbsen();
  }, () => {}, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
}
if (ABSEN) setInterval(() => { if (!document.hidden && Date.now() - statusTerakhir > 30000) loadAbsen(); }, 10000);

async function loadAbsen() {
  if (!ABSEN || absenMemuat || busy) return;   // tidak saat kamera merekam
  absenMemuat = true;
  const sub = $('#absen-sub'), notice = $('#absen-notice'), catatan = $('#absen-catatan'), wajib = $('#absen-wajib'), lokasi = $('#absen-lokasi');
  try {
    // Lokasi dibaca dulu: tombol hanya aktif bila JADWAL dan LOKASI sesuai (diputuskan server).
    lokasi.className = 'absen-lokasi';
    if (!statusTerakhir) lokasi.textContent = 'Membaca lokasi GPS…';
    const gps = posisiTerakhir && Date.now() - posisiTerakhir.waktu < 20000 ? posisiTerakhir : await getLocation();
    const data = await api('absen/status', gps.location ? { location: gps.location } : { location_error: gps.error || 'Lokasi tidak tersedia' });
    statusTerakhir = Date.now();
    posisiTerkirim = gps.location || null;
    mulaiPantauGPS();
    // Upacara kini dipilih bersama WFO/FWA di tombol absen masuk; tautan lama ?mode=upacara hanya menampilkan upacara.
    data.opsi = (data.opsi || []).filter(o => ABSEN_MODE !== 'upacara' || o.aksi === 'upacara');
    const jam = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const jarak = data.opsi.filter(o => typeof o.jarak_m === 'number').map(o => o.jarak_m);
    if (gps.location) lokasi.textContent = `📍 Akurasi ±${Math.round(gps.location.accuracy_m)} m` + (jarak.length ? ` · ${Math.min(...jarak).toLocaleString('id-ID')} m ke area absen` : '') + ` · diperbarui ${jam}`;
    else { lokasi.className = 'absen-lokasi gagal'; lokasi.textContent = `⚠ ${data.lokasi_pesan || gps.error || 'Lokasi tidak tersedia'}`; }
    const judul = $('#absen-judul');
    if (judul) judul.textContent = ABSEN_MODE === 'upacara' ? 'Absen Upacara' : (data.metode === 'WAJAH' ? 'Absen Wajah' : 'Absen');
    if (ABSEN_MODE === 'upacara' && !data.opsi.length) { notice.textContent = 'Tidak ada jadwal upacara hari ini untuk Anda.'; notice.hidden = false; }
    sub.textContent = `${data.hari}, ${data.tanggal} · jam server ${data.jam_server} WITA`;
    // Cuti / dinas / libur
    const notes = [];
    if (data.absen_lain?.pesan) notes.push(data.absen_lain.pesan + (data.absen_lain.keterangan ? ` (${data.absen_lain.keterangan})` : '') + '.');
    if (data.libur) notes.push(`Hari ini ${data.libur_nama || 'hari libur'}.`);
    // [temanku] jadwal shift APEL, presensi belum berlaku, lokasi absen yang sah
    if (data.belum_berlaku) notes.push(`Presensi TEMANKU berlaku mulai ${data.presensi_mulai}; absen hari ini belum dihitung di rekap.`);
    if (data.jadwal_shift) notes.push(data.jadwal_shift.kode === 'LIBUR' ? 'Hari ini LIBUR sesuai jadwal shift — tidak wajib absen.'
      : `Jadwal shift hari ini: ${data.jadwal_shift.nama}${data.jadwal_shift.jam ? ' (' + data.jadwal_shift.jam + ')' : ''}.`);
    if (ABSEN_MODE !== 'upacara' && (data.lokasi_sah || []).length) notes.push('Lokasi absen Anda: ' + data.lokasi_sah.join(', ') + '.');
    if (!(ABSEN_MODE === 'upacara' && !data.opsi.length)) {
      notice.textContent = notes.join(' ');
      notice.hidden = notes.length === 0;
    }
    // Catatan presensi hari ini (termasuk shift malam yang dimulai kemarin)
    const rows = (data.catatan || []).map(r => {
      const row = document.createElement('div');
      row.className = 'absen-catatan__row';
      const name = document.createElement('div');
      const title = document.createElement('b'); title.textContent = r.pola_nama || r.pola;
      const meta = document.createElement('small');
      const jenis = { wfa: 'WFA', upacara: 'Upacara', dinas: 'Dinas dalam', jaga: 'Jaga', kantor: 'Kantor' }[r.jenis] || r.jenis;
      meta.textContent = `${jenis}${r.tanggal_dinas !== data.tanggal ? ` · tgl dinas ${r.tanggal_dinas}` : ''}`;
      name.append(title, meta);
      const masuk = document.createElement('span');
      masuk.textContent = r.tanpa_masuk ? 'tidak absen masuk' : `masuk ${r.masuk}`;
      if (r.tanpa_masuk) masuk.className = 'tm';
      const pulang = document.createElement('span');
      pulang.textContent = r.pulang ? `pulang ${r.pulang}` : (r.pola === 'LEPAS' || r.jenis === 'dinas' ? 'tercatat' : 'belum pulang');
      row.append(name, masuk, pulang);
      return row;
    });
    catatan.replaceChildren(...rows);
    catatan.hidden = rows.length === 0;
    // Pilihan absen: yang aktif di atas; yang belum waktunya tampil abu-abu dengan alasannya.
    absenMetode = data.metode === 'WAJAH' ? 'WAJAH' : 'LOKASI';
    const pakaiWajah = absenMetode === 'WAJAH';
    wajib.hidden = !pakaiWajah || enrolled;   // wajib rekam wajah hanya untuk unit bermetode wajah
    const groups = { masuk: [], pulang: [] };
    // WFA (FWA) dan upacara digabung dengan absen masuk kantor (WFO) menjadi satu tombol pilihan.
    const fwa = ABSEN_MODE === 'upacara' ? null : (data.opsi || []).find(o => o.aksi === 'wfa');
    const upacara = ABSEN_MODE === 'upacara' ? [] : (data.opsi || []).filter(o => o.aksi === 'upacara');
    const dasar = fwa || upacara[0];
    const wfo = dasar && (data.opsi || []).find(o => o.aksi === 'masuk' && o.pola === dasar.pola);
    for (const o of data.opsi || []) {
      if (dasar && (o === fwa || o === wfo || upacara.includes(o))) continue;
      (o.aksi === 'pulang' ? groups.pulang : groups.masuk).push(o);
    }
    if (dasar) {
      pilihanMasuk = [
        wfo && Object.assign({}, wfo, { label: 'WFO — bekerja di kantor' }),
        fwa && Object.assign({}, fwa, { label: 'FWA — bekerja dari wilayah Selayar' }),
        ...upacara,
      ].filter(Boolean);
      const aktif = pilihanMasuk.some(o => o.aktif);
      const nama = [wfo && 'WFO', fwa && 'FWA', upacara.length && 'Upacara'].filter(Boolean).join(' / ');
      groups.masuk.unshift({ aksi: 'pilih-masuk', pola: dasar.pola, label: `Absen masuk (${nama})`, tanggal_dinas: dasar.tanggal_dinas, aktif,
        jadwal_aktif: pilihanMasuk.some(o => o.jadwal_aktif),
        alasan: aktif ? '' : pilihanMasuk.map(o => o.alasan).filter(Boolean)[0] || 'Belum tersedia', lokasi: aktif ? `Pilih ${nama}` : '' });
    }
    tampilkanLaporan(Boolean(data.fwa_hari_ini));
    // Satu tombol: hanya absen yang berlaku sekarang (masuk ATAU pulang). Tanpa yang aktif: pilihan yang sesuai
    // jadwal (terhalang lokasi), atau satu tombol abu-abu dengan alasan terdekat (pulang bila sudah absen masuk).
    const tampil = { masuk: [], pulang: [] };
    const pilih = cek => { for (const k of ['masuk', 'pulang']) tampil[k] = groups[k].filter(cek); return tampil.masuk.length + tampil.pulang.length > 0; };
    if (!pilih(o => o.aktif) && !pilih(o => o.jadwal_aktif)) {
      const sudahMasuk = (data.catatan || []).some(r => r.tanggal_dinas === data.tanggal && !r.tanpa_masuk);
      const kunci = (sudahMasuk && groups.pulang.length) || !groups.masuk.length ? 'pulang' : 'masuk';
      if (groups[kunci][0]) tampil[kunci] = [groups[kunci][0]];
    }
    for (const [key, list] of Object.entries(tampil)) {
      list.sort((a, b) => Number(b.aktif) - Number(a.aktif));
      const container = $(`#absen-list-${key}`);
      container.replaceChildren(...list.map(o => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'absen-opsi';
        button.dataset.aksi = o.aksi;
        button.dataset.pola = o.pola || '';
        button.dataset.tanggal = o.tanggal_dinas || '';
        button.dataset.label = o.label;
        button.dataset.jadwal = o.jadwal || '';
        button.disabled = (pakaiWajah && !enrolled) || !o.aktif;
        const label = document.createElement('span'); label.textContent = o.label;
        if (o.jadwal === 'sesuai') { const t = document.createElement('em'); t.className = 'absen-opsi__jadwal'; t.textContent = 'sesuai jadwal'; label.append(' ', t); }
        const small = document.createElement('small');
        small.textContent = o.aktif
          ? [o.tanggal_dinas && o.tanggal_dinas !== data.tanggal ? `tanggal dinas ${o.tanggal_dinas}` : '', o.lokasi || ''].filter(Boolean).join(' · ')
          : (o.alasan || 'Belum tersedia');
        if (o.jadwal === 'beda' && o.aktif) small.textContent = ['berbeda dari jadwal shift Anda', small.textContent].filter(Boolean).join(' · ');
        button.append(label);
        if (small.textContent) button.append(small);
        return button;
      }));
      $(`#absen-group-${key}`).hidden = list.length === 0;
    }
    // Peta digambar SETELAH tombol; gangguan peta (mis. ubin/Leaflet gagal) tidak boleh menghalangi absen.
    try { gambarPeta(data, gps.location); } catch (e) { petaGagal(e); }
  } catch (error) {
    sub.textContent = error.message || 'Jadwal absen belum dapat dimuat';
  } finally {
    absenMemuat = false;
  }
}
$('#absen-refresh')?.addEventListener('click', () => loadAbsen());

// ---- Pilihan WFO / FWA (Kamis/Jumat) ----
let pilihanMasuk = [];
function bukaPilihMasuk() {
  const list = $('#absen-pilih-list');
  list.replaceChildren(...pilihanMasuk.map(o => {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'absen-opsi'; b.dataset.aksi = o.aksi; b.disabled = !o.aktif;
    const label = document.createElement('span'); label.textContent = o.label;
    const small = document.createElement('small'); small.textContent = o.aktif ? (o.lokasi || '') : (o.alasan || 'Belum tersedia');
    b.append(label, small);
    b.addEventListener('click', () => {
      $('#absen-pilih').hidden = true;
      const extra = { pola: o.pola, tanggal_dinas: o.tanggal_dinas, label: { wfa: 'Masuk FWA', upacara: o.label }[o.aksi] || 'Masuk WFO' };
      if (absenMetode === 'WAJAH') run('absen', o.aksi, extra); else absenTanpaWajah(o.aksi, extra);
    });
    return b;
  }));
  $('#absen-pilih').hidden = false;
}
$('#absen-pilih-batal')?.addEventListener('click', () => { $('#absen-pilih').hidden = true; });

// ---- Laporan harian FWA ----
let laporanTampil = false;
function tampilkanLaporan(show) {
  const box = $('#absen-laporan');
  if (!box) return;
  box.hidden = !show;
  if (show && !laporanTampil) muatLaporan({ op: 'list' });
  laporanTampil = show;
}
async function muatLaporan(body) {
  const pesan = $('#laporan-pesan');
  try {
    const data = await api('absen/laporan', body);
    const list = $('#laporan-list');
    list.replaceChildren(...(data.data || []).map(item => {
      const li = document.createElement('li');
      const teks = document.createElement('div'); teks.textContent = `${item.uraian} (${item.jam})`;
      const link = document.createElement('a'); link.href = item.url_bukti; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = item.url_bukti;
      li.append(teks, link);
      if (data.bisa_ubah) {
        const hapus = document.createElement('button'); hapus.type = 'button'; hapus.textContent = 'hapus';
        hapus.addEventListener('click', () => muatLaporan({ op: 'hapus', id: item.id }).then(() => { statusTerakhir = 0; loadAbsen(); }));
        li.append(' ', hapus);
      }
      return li;
    }));
    if (pesan) pesan.textContent = (data.data || []).length ? `${data.data.length} kegiatan tercatat.` : 'Belum ada kegiatan.';
    return data;
  } catch (error) {
    if (pesan) pesan.textContent = error.message || 'Laporan belum dapat dimuat';
    throw error;
  }
}
$('#laporan-form')?.addEventListener('submit', async event => {
  event.preventDefault();
  try {
    await muatLaporan({ op: 'tambah', uraian: $('#laporan-uraian').value, url_bukti: $('#laporan-url').value });
    $('#laporan-uraian').value = ''; $('#laporan-url').value = '';
    statusTerakhir = 0; loadAbsen();   // tombol pulang FWA dapat aktif
  } catch (e) { /* pesan sudah tampil */ }
});
for (const key of ['masuk', 'pulang']) {
  $(`#absen-list-${key}`)?.addEventListener('click', event => {
    const button = event.target.closest('.absen-opsi');
    if (!button || button.disabled) return;
    if (button.dataset.aksi === 'pilih-masuk') { bukaPilihMasuk(); return; }
    if (button.dataset.jadwal === 'beda' && !window.confirm(`Shift "${button.dataset.label}" berbeda dari jadwal shift Anda hari ini. Tetap absen dengan shift ini?`)) return;
    const extra = { pola: button.dataset.pola, tanggal_dinas: button.dataset.tanggal, label: button.dataset.label };
    if (absenMetode === 'WAJAH') run('absen', button.dataset.aksi, extra);
    else absenTanpaWajah(button.dataset.aksi, extra);
  });
}

voice.init();
loadStatus().then(loadAbsen);
loadSimpegPhoto();
(window.requestIdleCallback || (callback => setTimeout(callback, 1500)))(prefetchDetector);
