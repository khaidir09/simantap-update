// Patch bundle frontend: Jurnal Bimbingan PKL.
// Spesifikasi: docs/specs/jurnal-bimbingan-pkl.md — Dokumentasi patch: docs/patches/bimbingan-pkl.md
//  P0 Komponen halaman Bimbingan PKL (__pklHalaman + tab-tabnya) + peta class (__pklK),
//     disisipkan tepat sebelum peta class patch Dispensasi (const __dspK=).
//  P1 Menu Akademik: item "Bimbingan PKL" setelah "Jurnal Mengajar" (GURU, KEPALA_SEKOLAH, ADMIN).
//  P2 Router halaman: bimbingan-pkl -> __pklHalaman.
//  P3 Daftar halaman yang dikenal: tambah bimbingan-pkl.
// Iterasi 2: salin jurnal ke siswa lain di DU/DI yang sama (form jurnal + tombol "Salin" di daftar jurnal).
// Prasyarat: patch Kelola Guru (apply-kelola-guru-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-bimbingan-pkl-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-kelolaguru.js';
const OUT_NAME = 'index-CGWL69P7-pkl2.js';
// Keluaran versi sebelumnya; app.html yang masih menunjuk ke sini ikut diarahkan ulang
const OUT_LAMA = ['index-CGWL69P7-pkl1.js'];
const SRC_SHA256 = '1c0d511b30cc56b5bedac6cba674d9a2838c2592889de8bd93b6610da6a65c45';
const CSS_NAME = 'index-CC1PBzaO.css';

const srcPath = path.join(ROOT, 'assets', SRC_NAME);
const outPath = path.join(ROOT, 'assets', OUT_NAME);
const cssPath = path.join(ROOT, 'assets', CSS_NAME);
const appHtmlPath = path.join(ROOT, 'app.html');

function fail(msg) {
  console.error('GAGAL: ' + msg);
  process.exit(1);
}

const src = fs.readFileSync(srcPath, 'utf8');
const hash = crypto.createHash('sha256').update(fs.readFileSync(srcPath)).digest('hex');
if (hash !== SRC_SHA256) fail(`hash ${SRC_NAME} berbeda (${hash}). Bundle sudah berubah, cari ulang anchor secara manual.`);

// ---------------------------------------------------------------------------
// Semua class Tailwind (divalidasi terhadap CSS bundle di bawah).
// ---------------------------------------------------------------------------
const BADGE = 'inline-block text-[10px] font-black px-2 py-0.5 rounded-full';
const INPUT = 'w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-600';
const KELAS = {
  halaman: 'max-w-6xl mx-auto space-y-8 pb-20',
  wrap: 'space-y-6',
  bar: 'flex flex-wrap items-center justify-between gap-3',
  barKiri: 'flex flex-wrap items-center gap-2',
  select: 'px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-600',
  input: INPUT,
  textarea: INPUT,
  inputTanggal: 'px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600',
  label: 'text-[10px] font-black text-slate-400 uppercase tracking-widest',
  field: 'space-y-1',
  grid2: 'grid grid-cols-1 md:grid-cols-2 gap-4',
  penuh: 'md:col-span-2',
  btnPutih: 'flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-500 hover:bg-slate-50 transition-all',
  btnHijau: 'flex items-center gap-2 px-4 py-2 bg-emerald-600 border border-emerald-600 rounded-xl text-xs font-black text-white hover:bg-emerald-700 transition-all',
  btnMati: 'flex items-center gap-2 px-4 py-2 bg-slate-100 border border-slate-200 rounded-xl text-xs font-black text-slate-400 cursor-not-allowed',
  btnKecil: 'flex items-center gap-1 px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-[10px] font-black text-slate-500 hover:bg-slate-50 transition-all',
  btnKecilHijau: 'flex items-center gap-1 px-3 py-1.5 bg-emerald-600 border border-emerald-600 rounded-xl text-[10px] font-black text-white hover:bg-emerald-700 transition-all',
  btnMerah: 'flex items-center gap-1 px-3 py-1.5 bg-white border border-red-200 rounded-xl text-[10px] font-black text-red-600 hover:bg-red-50 transition-all',
  toggleWrap: 'flex flex-wrap bg-slate-100 p-1.5 rounded-2xl w-fit',
  toggleOn: 'px-4 py-2 rounded-xl text-xs font-black transition-all bg-white text-emerald-600 shadow-sm',
  toggleOff: 'px-4 py-2 rounded-xl text-xs font-black transition-all text-slate-400 hover:text-slate-600',
  card: 'bg-white rounded-[2.5rem] border border-slate-100 overflow-hidden shadow-sm',
  cardHead: 'p-6 border-b border-slate-50 flex flex-wrap items-center justify-between gap-3',
  cardBody: 'p-6 space-y-4',
  gridKartu: 'grid grid-cols-1 md:grid-cols-2 gap-4',
  kartu: 'bg-white rounded-3xl border border-slate-100 p-5 space-y-3 shadow-sm',
  kartuDalam: 'rounded-2xl border border-slate-100 bg-slate-50 p-4 space-y-2',
  judulKecil: 'text-xs font-black text-slate-400 uppercase tracking-widest',
  subjudul: 'text-lg font-black text-slate-800',
  nama: 'text-sm font-black text-slate-800',
  teks: 'text-sm text-slate-500',
  teksIsi: 'text-sm text-slate-600 whitespace-pre-wrap',
  teksKecil: 'text-[11px] font-bold text-slate-400',
  tautan: 'text-xs font-black text-emerald-600 hover:underline',
  infoHijau: 'p-4 rounded-2xl bg-emerald-50 text-emerald-700 text-sm font-bold',
  infoKuning: 'p-4 rounded-2xl bg-amber-50 text-amber-700 text-sm font-bold',
  daftarSiswa: 'max-h-[320px] overflow-y-auto border border-slate-100 rounded-2xl divide-y divide-slate-50',
  barisSiswa: 'flex items-center gap-3 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 cursor-pointer',
  barisMati: 'flex items-center gap-3 px-4 py-2 text-sm font-bold text-slate-400',
  checkLabel: 'flex items-center gap-2 text-xs font-black text-slate-500',
  tabelWrap: 'overflow-x-auto',
  tabel: 'w-full text-left',
  thead: 'bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest',
  th: 'px-4 py-3 whitespace-nowrap',
  tbody: 'divide-y divide-slate-50',
  td: 'px-4 py-3 text-sm text-slate-600',
  tdNama: 'px-4 py-3 text-sm font-bold text-slate-700 whitespace-nowrap',
  badgeHijau: BADGE + ' bg-emerald-50 text-emerald-700',
  badgeKuning: BADGE + ' bg-amber-50 text-amber-700',
  badgeAbu: BADGE + ' bg-slate-100 text-slate-500',
  badgeUngu: BADGE + ' bg-indigo-50 text-indigo-700',
  badgeMerah: BADGE + ' bg-red-50 text-red-600',
  foto: 'w-20 h-20 object-cover rounded-xl border border-slate-200',
  fotoWrap: 'flex flex-wrap gap-2',
  fotoItem: 'space-y-1',
  kosong: 'py-16 text-center text-slate-400 font-bold text-sm',
  spinnerWrap: 'py-20 text-center space-y-3',
  spinner: 'w-10 h-10 border-4 border-emerald-600 border-t-transparent rounded-full animate-spin mx-auto',
  galat: 'flex items-center gap-3 p-5 rounded-3xl border border-red-100 bg-red-50 text-red-700 text-sm font-bold',
};
// Class yang ditulis langsung di teks patch (bukan lewat __pklK)
const KELAS_PATCH = ['text-white/80'];

// ---------------------------------------------------------------------------
// P0 Komponen. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas dari scope modul bundle:
//   a = jsx runtime, ue = React, K$ = URL api/index.php, zi = kartu judul halaman,
//   ikon lucide: dW (briefcase), Z1 (triangle-alert), po (refresh-cw), Ss (printer), es (plus).
// __pklK = peta class Tailwind (KELAS di atas).
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */

function __pklH(t, p, ...c) {
  const { key, ...rest } = p || {};
  if (c.length) rest.children = c.length === 1 ? c[0] : c;
  return a.jsx(t, rest, key);
}

async function __pklJson(url, opsi) {
  const res = await fetch(url, opsi);
  let j = null;
  try { j = await res.json(); } catch (e) { j = null; }
  if (!res.ok) {
    const pesan = j && j.error ? j.error : `Gagal memuat data (HTTP ${res.status}).`;
    const err = new Error(pesan === 'Unauthorized' ? 'Sesi berakhir, silakan login ulang.' : pesan);
    err.data = j;
    err.status = res.status;
    throw err;
  }
  return j;
}

function __pklGet(action, q) {
  return __pklJson(`${K$}?action=${action}${q || ''}`);
}

function __pklPost(action, body) {
  return __pklJson(`${K$}?action=${action}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
}

function __pklIso(d) {
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

function __pklTgl(d) {
  const p = String(d || '').slice(0, 10).split('-');
  return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : '-';
}

function __pklCetak(q) {
  window.open(`${K$}?action=${q}`, '_blank', 'noopener');
}

function __pklGalat({ pesan }) {
  if (!pesan) return null;
  return __pklH('div', { className: __pklK.galat }, __pklH(Z1, { size: 18 }), __pklH('span', null, pesan));
}

function __pklMemuat() {
  return __pklH('div', { className: __pklK.spinnerWrap }, __pklH('div', { className: __pklK.spinner }));
}

function __pklField(label, isi, lebar) {
  return __pklH('div', { className: lebar ? `${__pklK.field} ${__pklK.penuh}` : __pklK.field }, __pklH('p', { className: __pklK.label }, label), isi);
}

function __pklBadgeStatus({ status }) {
  const K = __pklK;
  const peta = { AKTIF: [K.badgeHijau, 'Aktif'], AKAN_DATANG: [K.badgeKuning, 'Akan datang'], SELESAI: [K.badgeAbu, 'Selesai'] };
  const [c, l] = peta[status] || [K.badgeAbu, status];
  return __pklH('span', { className: c }, l);
}

// Kartu satu penempatan (siswa bimbingan) + tombol aksi dari pemanggil.
function __pklKartuPenempatan({ p, tampilGuru, children }) {
  const K = __pklK;
  const h = __pklH;
  return h('div', { className: K.kartu },
    h('div', { className: K.bar },
      h('div', null,
        h('p', { className: K.nama }, p.siswa_nama),
        h('p', { className: K.teksKecil }, `${p.kelas_nama}${p.nisn ? ` · NISN ${p.nisn}` : ''}`)),
      h('div', { className: K.barKiri },
        h(__pklBadgeStatus, { status: p.status }),
        p.siswa_status && h('span', { className: K.badgeMerah }, p.siswa_status === 'TERHAPUS' ? 'Data siswa terhapus' : `Siswa: ${p.siswa_status}`))),
    h('div', { className: K.kartuDalam },
      h('p', { className: K.nama }, p.dudi_nama),
      p.dudi_alamat && h('p', { className: K.teksKecil }, p.dudi_alamat),
      p.dudi_pembimbing && h('p', { className: K.teksKecil }, `Pembimbing industri: ${p.dudi_pembimbing}${p.dudi_telp ? ` (${p.dudi_telp})` : ''}`)),
    h('p', { className: K.teksKecil },
      `Periode ${__pklTgl(p.tanggal_mulai)} – ${__pklTgl(p.tanggal_selesai)} · ${p.jumlah_jurnal} jurnal`
      + (p.jurnal_terakhir ? ` · terakhir ${__pklTgl(p.jurnal_terakhir)}` : '')
      + (tampilGuru ? ` · Pembimbing: ${p.guru_nama}` : '')),
    children && h('div', { className: K.barKiri }, children));
}

// Daftar jurnal (kartu). onUbah/onSalin/onHapus opsional; tombol muncul sesuai hak di tiap jurnal.
function __pklDaftarJurnal({ items, onUbah, onSalin, onHapus, tampilGuru, kosong }) {
  const K = __pklK;
  const h = __pklH;
  if (!items.length) return h('p', { className: K.kosong }, kosong || 'Belum ada jurnal bimbingan.');
  return h('div', { className: K.gridKartu }, items.map((j) => h('div', { key: j.id, className: K.kartu },
    h('div', { className: K.bar },
      h('div', null,
        h('p', { className: K.nama }, `${__pklTgl(j.tanggal)} · ${j.jam_mulai}–${j.jam_selesai}`),
        h('p', { className: K.teksKecil }, `${j.siswa_nama || '-'}${j.kelas_nama ? ` (${j.kelas_nama})` : ''} · ${j.dudi_nama || '-'}`)),
      h('span', { className: K.badgeUngu }, j.jenis_label)),
    h('p', { className: K.teksIsi }, j.uraian),
    j.kondisi_siswa && h('p', { className: K.teks }, h('b', null, 'Kondisi/perkembangan: '), j.kondisi_siswa),
    j.permasalahan && h('p', { className: K.teks }, h('b', null, 'Permasalahan: '), j.permasalahan),
    j.tindak_lanjut && h('p', { className: K.teks }, h('b', null, 'Tindak lanjut: '), j.tindak_lanjut),
    j.foto && j.foto.length > 0 && h('div', { className: K.fotoWrap }, j.foto.map((f) => h('a', { key: f, href: f, target: '_blank', rel: 'noopener noreferrer' },
      h('img', { src: f, alt: 'Foto bimbingan', className: K.foto })))),
    tampilGuru && h('p', { className: K.teksKecil }, `Ditulis oleh ${j.guru_nama}`),
    ((onUbah && j.bisa_ubah) || (onSalin && j.milik) || (onHapus && j.bisa_hapus)) && h('div', { className: K.barKiri },
      onUbah && j.bisa_ubah && h('button', { onClick: () => onUbah(j), className: K.btnKecil }, 'Ubah'),
      onSalin && j.milik && h('button', { onClick: () => onSalin(j), className: K.btnKecil }, 'Salin ke siswa lain'),
      onHapus && j.bisa_hapus && h('button', { onClick: () => onHapus(j), className: K.btnMerah }, 'Hapus')))));
}

// Jurnal satu penempatan (dibuka dari Pantau / Siswa PKL Kelas Saya).
function __pklJurnalPenempatan({ p, bolehHapus }) {
  const K = __pklK;
  const h = __pklH;
  const [items, setItems] = ue.useState(null);
  const [galat, setGalat] = ue.useState('');
  const muat = ue.useCallback(() => {
    setGalat('');
    __pklGet('pkl_jurnal_list', `&penempatan_id=${encodeURIComponent(p.id)}`)
      .then((r) => setItems(r.items || []))
      .catch((e) => { setGalat(e.message); setItems([]); });
  }, [p.id]);
  ue.useEffect(() => { muat(); }, [muat]);
  const hapus = async (j) => {
    if (!window.confirm(`Hapus jurnal ${__pklTgl(j.tanggal)} untuk ${j.siswa_nama}?`)) return;
    try { await __pklPost('pkl_jurnal_hapus', { id: j.id }); muat(); } catch (e) { setGalat(e.message); }
  };
  return h('div', { className: K.cardBody },
    h(__pklGalat, { pesan: galat }),
    items === null ? h(__pklMemuat, null)
      : h(__pklDaftarJurnal, { items, tampilGuru: true, onHapus: bolehHapus ? hapus : null, kosong: 'Belum ada jurnal untuk penempatan ini.' }));
}

// Form DU/DI (tambah/ubah). 409 nama sama: tawarkan DU/DI yang sudah ada.
function __pklFormDudi({ awal, onTersimpan, onBatal }) {
  const K = __pklK;
  const h = __pklH;
  const [f, setF] = ue.useState(() => ({
    nama: '', bidang_usaha: '', alamat: '', pembimbing_nama: '', pembimbing_telp: '', catatan: '', ...(awal || {}),
  }));
  const [proses, setProses] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const [ada, setAda] = ue.useState(null);
  const ubah = (k, v) => setF((x) => ({ ...x, [k]: v }));
  const simpan = async () => {
    setProses(true);
    setGalat('');
    setAda(null);
    try {
      const r = await __pklPost('pkl_dudi_simpan', {
        id: f.id || '', nama: f.nama, bidang_usaha: f.bidang_usaha, alamat: f.alamat,
        pembimbing_nama: f.pembimbing_nama, pembimbing_telp: f.pembimbing_telp, catatan: f.catatan,
      });
      onTersimpan(r.item);
    } catch (e) {
      setGalat(e.message);
      if (e.status === 409 && e.data && e.data.existing && !f.id) setAda(e.data.existing);
    } finally {
      setProses(false);
    }
  };
  const isian = (k, label, opsi) => __pklField(label, h('input', { className: K.input, value: f[k] || '', onChange: (e) => ubah(k, e.target.value), ...(opsi || {}) }));
  return h('div', { className: K.kartuDalam },
    h('p', { className: K.label }, f.id ? 'Ubah DU/DI' : 'Tambah DU/DI baru'),
    h(__pklGalat, { pesan: galat }),
    ada && h('div', { className: K.barKiri },
      h('button', { onClick: () => onTersimpan(ada), className: K.btnKecilHijau }, `Gunakan yang ada: ${ada.nama}`)),
    h('div', { className: K.grid2 },
      isian('nama', 'Nama DU/DI *', { maxLength: 255, placeholder: 'Contoh: PT Maju Jaya' }),
      isian('bidang_usaha', 'Bidang usaha', { maxLength: 150 }),
      isian('pembimbing_nama', 'Nama pembimbing industri', { maxLength: 150 }),
      isian('pembimbing_telp', 'Telepon pembimbing industri', { maxLength: 30, inputMode: 'tel' }),
      __pklField('Alamat', h('textarea', { className: K.textarea, rows: 2, value: f.alamat || '', maxLength: 1000, onChange: (e) => ubah('alamat', e.target.value) }), true),
      __pklField('Catatan', h('textarea', { className: K.textarea, rows: 2, value: f.catatan || '', maxLength: 2000, onChange: (e) => ubah('catatan', e.target.value) }), true)),
    h('div', { className: K.barKiri },
      h('button', { disabled: proses || !String(f.nama).trim(), onClick: simpan, className: proses || !String(f.nama).trim() ? K.btnMati : K.btnHijau }, proses ? 'Menyimpan...' : 'Simpan DU/DI'),
      h('button', { onClick: onBatal, className: K.btnPutih }, 'Batal')));
}

// Pemilih DU/DI dengan pencarian + tambah baru.
function __pklPilihDudi({ value, onChange, bolehTambah }) {
  const K = __pklK;
  const h = __pklH;
  const [daftar, setDaftar] = ue.useState([]);
  const [cari, setCari] = ue.useState('');
  const [tambah, setTambah] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const muat = ue.useCallback(() => {
    __pklGet('pkl_dudi_list').then((r) => setDaftar(r.items || [])).catch((e) => setGalat(e.message));
  }, []);
  ue.useEffect(() => { muat(); }, [muat]);
  const q = cari.trim().toLowerCase();
  const tampil = daftar.filter((d) => !q || d.id === value || `${d.nama} ${d.alamat || ''} ${d.bidang_usaha || ''}`.toLowerCase().includes(q));
  const dipilih = daftar.find((d) => d.id === value);
  return h('div', { className: K.field },
    h('p', { className: K.label }, 'DU/DI tempat PKL *'),
    h(__pklGalat, { pesan: galat }),
    h('div', { className: K.barKiri },
      h('input', { className: K.inputTanggal, placeholder: 'Cari DU/DI...', value: cari, onChange: (e) => setCari(e.target.value) }),
      h('select', { className: K.select, value: value || '', onChange: (e) => onChange(e.target.value) },
        h('option', { value: '' }, `-- Pilih DU/DI (${tampil.length}) --`),
        tampil.map((d) => h('option', { key: d.id, value: d.id }, d.nama))),
      bolehTambah && !tambah && h('button', { onClick: () => setTambah(true), className: K.btnKecil }, h(es, { size: 12 }), 'Tambah DU/DI baru')),
    dipilih && h('p', { className: K.teksKecil }, [dipilih.alamat, dipilih.pembimbing_nama && `Pembimbing industri: ${dipilih.pembimbing_nama}`].filter(Boolean).join(' · ') || ' '),
    tambah && h(__pklFormDudi, {
      awal: { nama: cari },
      onTersimpan: (d) => { setTambah(false); setCari(''); muat(); onChange(d.id); },
      onBatal: () => setTambah(false),
    }));
}

// Form penempatan: buat (pilih siswa XII) atau ubah (DU/DI & periode).
// guruPilihan: daftar guru memenuhi syarat (khusus ADMIN membuat untuk guru lain).
function __pklFormPenempatan({ edit, guruPilihan, onSelesai, onBatal }) {
  const K = __pklK;
  const h = __pklH;
  const hariIni = __pklIso(new Date());
  const tigaBulan = __pklIso(new Date(Date.now() + 90 * 86400000));
  const [kelas, setKelas] = ue.useState('');
  const [kandidat, setKandidat] = ue.useState({ kelas: [], siswa: [] });
  const [pilih, setPilih] = ue.useState({});
  const [cari, setCari] = ue.useState('');
  const [dudi, setDudi] = ue.useState(edit ? edit.dudi_id : '');
  const [mulai, setMulai] = ue.useState(edit ? edit.tanggal_mulai : hariIni);
  const [selesai, setSelesai] = ue.useState(edit ? edit.tanggal_selesai : tigaBulan);
  const [guru, setGuru] = ue.useState('');
  const [proses, setProses] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const [gagal, setGagal] = ue.useState([]);
  const [versi, setVersi] = ue.useState(0);

  ue.useEffect(() => {
    if (edit) return undefined;
    let batal = false;
    __pklGet('pkl_kandidat_siswa', kelas ? `&kelas_id=${encodeURIComponent(kelas)}` : '')
      .then((r) => { if (!batal) setKandidat({ kelas: r.kelas || [], siswa: r.siswa || [] }); })
      .catch((e) => { if (!batal) setGalat(e.message); });
    return () => { batal = true; };
  }, [kelas, edit, versi]);

  const q = cari.trim().toLowerCase();
  const siswaTampil = kandidat.siswa.filter((s) => !q || String(s.nama).toLowerCase().includes(q) || String(s.nisn || '').includes(q));
  const terpilih = kandidat.siswa.filter((s) => pilih[s.id] && !s.penempatan_aktif).map((s) => s.id);
  const bisaSimpan = !proses && dudi && mulai && selesai && (edit || (terpilih.length > 0 && (!guruPilihan || guru)));

  const simpan = async () => {
    setProses(true);
    setGalat('');
    setGagal([]);
    try {
      const body = edit
        ? { id: edit.id, dudi_id: dudi, tanggal_mulai: mulai, tanggal_selesai: selesai }
        : { siswa_ids: terpilih, dudi_id: dudi, tanggal_mulai: mulai, tanggal_selesai: selesai, guru_id: guru };
      const r = await __pklPost('pkl_penempatan_simpan', body);
      const n = (r.tersimpan || []).length;
      if (r.gagal && r.gagal.length) {
        setGagal(r.gagal);
        setPilih({});
        setVersi((v) => v + 1);
        onSelesai(`${n} penempatan tersimpan. ${r.gagal.length} siswa gagal, lihat rincian di form.`, true);
      } else {
        onSelesai(edit ? 'Penempatan diperbarui.' : `${n} siswa bimbingan tersimpan.`, false);
      }
    } catch (e) {
      setGalat(e.message);
      if (e.data && Array.isArray(e.data.gagal)) setGagal(e.data.gagal);
    } finally {
      setProses(false);
    }
  };

  return h('div', { className: K.card },
    h('div', { className: K.cardHead },
      h('p', { className: K.subjudul }, edit ? `Ubah Penempatan — ${edit.siswa_nama}` : 'Tambah Siswa Bimbingan'),
      h('button', { onClick: onBatal, className: K.btnPutih }, 'Tutup')),
    h('div', { className: K.cardBody },
      h(__pklGalat, { pesan: galat }),
      gagal.length > 0 && h('div', { className: K.infoKuning }, gagal.map((g) => h('p', { key: g.siswa_id }, `${g.nama}: ${g.alasan}`))),
      edit && edit.jumlah_jurnal > 0 && h('p', { className: K.teksKecil }, 'Penempatan ini sudah memiliki jurnal: DU/DI tidak dapat diganti (gunakan Tutup lalu buat penempatan baru), dan periode harus tetap mencakup tanggal jurnal.'),
      guruPilihan && !edit && __pklField('Guru pembimbing *', h('select', { className: K.select, value: guru, onChange: (e) => setGuru(e.target.value) },
        h('option', { value: '' }, '-- Pilih guru (penugasan mapel kelas XII) --'),
        guruPilihan.map((g) => h('option', { key: g.uid, value: g.uid }, g.nama)))),
      h(__pklPilihDudi, { value: dudi, onChange: setDudi, bolehTambah: !(edit && edit.jumlah_jurnal > 0) }),
      h('div', { className: K.grid2 },
        __pklField('Tanggal mulai PKL', h('input', { type: 'date', className: K.input, value: mulai, onChange: (e) => setMulai(e.target.value) })),
        __pklField('Tanggal selesai PKL', h('input', { type: 'date', className: K.input, value: selesai, min: mulai, onChange: (e) => setSelesai(e.target.value) }))),
      !edit && h('div', { className: K.bar },
        h('p', { className: K.label }, `Siswa kelas XII (${terpilih.length} dipilih)`),
        h('div', { className: K.barKiri },
          h('select', { className: K.select, value: kelas, onChange: (e) => { setKelas(e.target.value); setPilih({}); } },
            h('option', { value: '' }, 'Semua kelas XII'),
            kandidat.kelas.map((c) => h('option', { key: c.id, value: c.id }, c.nama))),
          h('input', { className: K.inputTanggal, placeholder: 'Cari nama/NISN...', value: cari, onChange: (e) => setCari(e.target.value) }))),
      !edit && h('div', { className: K.daftarSiswa },
        siswaTampil.length === 0 ? h('p', { className: K.kosong }, 'Tidak ada siswa aktif kelas XII.')
          : siswaTampil.map((s) => (s.penempatan_aktif
            ? h('div', { key: s.id, className: K.barisMati },
              h('input', { type: 'checkbox', disabled: true, checked: false, readOnly: true }),
              h('span', null, s.nama),
              h('span', { className: K.teksKecil }, `${s.kelas_nama} · Dibimbing: ${s.penempatan_aktif.guru_nama} (${__pklTgl(s.penempatan_aktif.tanggal_mulai)} – ${__pklTgl(s.penempatan_aktif.tanggal_selesai)})`))
            : h('label', { key: s.id, className: K.barisSiswa },
              h('input', { type: 'checkbox', checked: !!pilih[s.id], onChange: (e) => { const v = e.target.checked; setPilih((x) => ({ ...x, [s.id]: v })); } }),
              h('span', null, s.nama),
              h('span', { className: K.teksKecil }, `${s.kelas_nama}${s.nisn ? ` · ${s.nisn}` : ''}`))))),
      h('div', { className: K.bar },
        h('p', { className: K.teksKecil }, 'Satu siswa hanya bisa punya satu pembimbing pada periode yang sama.'),
        h('button', { disabled: !bisaSimpan, onClick: simpan, className: bisaSimpan ? K.btnHijau : K.btnMati },
          proses ? 'Menyimpan...' : (edit ? 'Simpan Perubahan' : `Simpan (${terpilih.length} siswa)`)))));
}

// Form tutup penempatan: majukan tanggal selesai.
function __pklFormTutup({ p, onSelesai, onBatal }) {
  const K = __pklK;
  const h = __pklH;
  const hariIni = __pklIso(new Date());
  let awal = hariIni < p.tanggal_selesai ? hariIni : p.tanggal_selesai;
  if (awal < p.tanggal_mulai) awal = p.tanggal_mulai;
  if (p.jurnal_terakhir && awal < p.jurnal_terakhir) awal = p.jurnal_terakhir;
  const [tgl, setTgl] = ue.useState(awal);
  const [proses, setProses] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const simpan = async () => {
    setProses(true);
    setGalat('');
    try {
      await __pklPost('pkl_penempatan_tutup', { id: p.id, tanggal_selesai: tgl });
      onSelesai(`Penempatan ${p.siswa_nama} ditutup per ${__pklTgl(tgl)}. Siswa dapat ditempatkan lagi mulai hari berikutnya.`);
    } catch (e) {
      setGalat(e.message);
    } finally {
      setProses(false);
    }
  };
  return h('div', { className: K.kartuDalam },
    h('p', { className: K.label }, 'Tutup penempatan (pindah DU/DI atau lepas siswa)'),
    h(__pklGalat, { pesan: galat }),
    h('div', { className: K.barKiri },
      h('input', { type: 'date', className: K.inputTanggal, value: tgl, min: p.jurnal_terakhir || p.tanggal_mulai, max: p.tanggal_selesai, onChange: (e) => setTgl(e.target.value) }),
      h('button', { disabled: proses || !tgl, onClick: simpan, className: proses || !tgl ? K.btnMati : K.btnHijau }, proses ? 'Menyimpan...' : 'Tutup per tanggal ini'),
      h('button', { onClick: onBatal, className: K.btnPutih }, 'Batal')));
}

// Form alih pembimbing (ADMIN).
function __pklFormAlih({ p, guruPilihan, onSelesai, onBatal }) {
  const K = __pklK;
  const h = __pklH;
  const hariIni = __pklIso(new Date());
  let awal = hariIni < p.tanggal_mulai ? p.tanggal_mulai : hariIni;
  if (p.jurnal_terakhir && awal < p.jurnal_terakhir) awal = p.jurnal_terakhir;
  const [tgl, setTgl] = ue.useState(awal);
  const [guru, setGuru] = ue.useState('');
  const [proses, setProses] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const simpan = async () => {
    setProses(true);
    setGalat('');
    try {
      const r = await __pklPost('pkl_penempatan_alih', { id: p.id, guru_id_baru: guru, tanggal_alih: tgl });
      onSelesai(`${p.siswa_nama} dialihkan ke ${r.baru.guru_nama} mulai ${__pklTgl(r.baru.tanggal_mulai)}.`);
    } catch (e) {
      setGalat(e.message);
    } finally {
      setProses(false);
    }
  };
  const ok = !proses && guru && tgl;
  return h('div', { className: K.kartuDalam },
    h('p', { className: K.label }, 'Alih pembimbing'),
    h('p', { className: K.teksKecil }, 'Penempatan lama berakhir pada tanggal alih; pembimbing baru mulai hari berikutnya. Jurnal lama tetap milik guru lama.'),
    h(__pklGalat, { pesan: galat }),
    h('div', { className: K.barKiri },
      h('select', { className: K.select, value: guru, onChange: (e) => setGuru(e.target.value) },
        h('option', { value: '' }, '-- Guru pembimbing baru --'),
        guruPilihan.filter((g) => g.uid !== p.guru_id).map((g) => h('option', { key: g.uid, value: g.uid }, g.nama))),
      h('input', { type: 'date', className: K.inputTanggal, value: tgl, min: p.jurnal_terakhir || p.tanggal_mulai, max: p.tanggal_selesai, onChange: (e) => setTgl(e.target.value) }),
      h('button', { disabled: !ok, onClick: simpan, className: ok ? K.btnHijau : K.btnMati }, proses ? 'Menyimpan...' : 'Alihkan'),
      h('button', { onClick: onBatal, className: K.btnPutih }, 'Batal')));
}

// Form jurnal bimbingan (tambah/ubah).
function __pklFormJurnal({ awal, penempatan, onSelesai, onBatal }) {
  const K = __pklK;
  const h = __pklH;
  const hariIni = __pklIso(new Date());
  const edit = !!(awal && awal.id);
  // Mode salin: isian diambil dari jurnal yang sudah ada, siswa tujuan dipilih lewat daftar centang
  const asal = (!edit && awal && awal.salinDari) || null;
  const [f, setF] = ue.useState(() => ({
    tanggal: hariIni, penempatan_id: '', jam_mulai: '', jam_selesai: '', jenis: 'KUNJUNGAN',
    uraian: '', kondisi_siswa: '', permasalahan: '', tindak_lanjut: '', foto: [], ...(awal || {}),
  }));
  const [salin, setSalin] = ue.useState({});
  const [proses, setProses] = ue.useState(false);
  const [unggah, setUnggah] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const ubah = (k, v) => setF((x) => ({ ...x, [k]: v }));
  const cocok = penempatan.filter((p) => p.tanggal_mulai <= f.tanggal && p.tanggal_selesai >= f.tanggal);
  const pilihan = edit ? penempatan.filter((p) => p.id === f.penempatan_id) : cocok;
  const dipilih = penempatan.find((p) => p.id === f.penempatan_id);
  // Siswa lain milik guru ini di DU/DI yang sama, dengan periode yang mencakup tanggal jurnal
  const dudiAcuan = asal ? asal.dudi_id : (dipilih ? dipilih.dudi_id : '');
  const kecuali = asal ? asal.penempatan_id : f.penempatan_id;
  const serumpun = edit || !dudiAcuan ? [] : cocok.filter((p) => p.dudi_id === dudiAcuan && p.id !== kecuali);
  const tujuanSalin = serumpun.filter((p) => salin[p.id]).map((p) => p.id);
  const semuaDipilih = serumpun.length > 0 && tujuanSalin.length === serumpun.length;

  const pilihFoto = async (e) => {
    const berkas = e.target.files && e.target.files[0];
    e.target.value = '';
    if (!berkas) return;
    if (berkas.size > 2 * 1024 * 1024) { setGalat('Ukuran foto maksimal 2 MB'); return; }
    setUnggah(true);
    setGalat('');
    try {
      const fd = new FormData();
      fd.append('file', berkas);
      const r = await __pklJson(`${K$}?action=pkl_unggah_foto`, { method: 'POST', body: fd });
      setF((x) => ({ ...x, foto: [...(x.foto || []), r.path].slice(0, 3) }));
    } catch (err) {
      setGalat(err.message);
    } finally {
      setUnggah(false);
    }
  };

  const simpan = async () => {
    setProses(true);
    setGalat('');
    try {
      const utama = asal ? tujuanSalin[0] : f.penempatan_id;
      const r = await __pklPost('pkl_jurnal_simpan', {
        id: edit ? f.id : '', penempatan_id: utama, tanggal: f.tanggal, jam_mulai: f.jam_mulai, jam_selesai: f.jam_selesai,
        jenis: f.jenis, uraian: f.uraian, kondisi_siswa: f.kondisi_siswa, permasalahan: f.permasalahan, tindak_lanjut: f.tindak_lanjut, foto: f.foto || [],
        salin_ke: edit ? [] : tujuanSalin.filter((id) => id !== utama),
      });
      const n = (r && r.jumlah) || 1;
      onSelesai(edit ? 'Jurnal bimbingan diperbarui.' : (n > 1 ? `Jurnal bimbingan tersimpan untuk ${n} siswa.` : 'Jurnal bimbingan tersimpan.'), f.tanggal);
    } catch (e) {
      setGalat(e.message);
    } finally {
      setProses(false);
    }
  };
  const ok = !proses && !unggah && (asal ? tujuanSalin.length > 0 : f.penempatan_id) && f.tanggal && f.jam_mulai && f.jam_selesai && String(f.uraian).trim();
  const jumlahSimpan = (asal ? 0 : 1) + tujuanSalin.length;
  const teks = (k, label, maks, baris) => __pklField(label, h('textarea', {
    className: K.textarea, rows: baris || 2, maxLength: maks, value: f[k] || '', onChange: (e) => ubah(k, e.target.value),
  }), true);

  return h('div', { className: K.card },
    h('div', { className: K.cardHead },
      h('p', { className: K.subjudul }, edit ? 'Ubah Jurnal Bimbingan' : asal ? `Salin Jurnal ${asal.siswa_nama || ''}` : 'Tambah Jurnal Bimbingan'),
      h('button', { onClick: onBatal, className: K.btnPutih }, 'Tutup')),
    h('div', { className: K.cardBody },
      h(__pklGalat, { pesan: galat }),
      h('div', { className: K.grid2 },
        __pklField('Tanggal', h('input', { type: 'date', className: K.input, value: f.tanggal, max: hariIni, onChange: (e) => ubah('tanggal', e.target.value) })),
        asal ? __pklField('Disalin dari', h('p', { className: K.teks }, `${asal.siswa_nama || '-'} · ${__pklTgl(asal.tanggal)} ${asal.jam_mulai}–${asal.jam_selesai}`))
          : __pklField('Siswa bimbingan', h('select', {
            className: K.select, value: f.penempatan_id, disabled: edit, onChange: (e) => ubah('penempatan_id', e.target.value),
          },
          h('option', { value: '' }, pilihan.length ? '-- Pilih siswa --' : 'Tidak ada siswa bimbingan pada tanggal ini'),
          pilihan.map((p) => h('option', { key: p.id, value: p.id }, `${p.siswa_nama} (${p.kelas_nama})`)))),
        __pklField('Jam mulai', h('input', { type: 'time', className: K.input, value: f.jam_mulai, onChange: (e) => ubah('jam_mulai', e.target.value) })),
        __pklField('Jam selesai', h('input', { type: 'time', className: K.input, value: f.jam_selesai, onChange: (e) => ubah('jam_selesai', e.target.value) })),
        __pklField('DU/DI', h('p', { className: K.teks }, asal ? (asal.dudi_nama || '-')
          : dipilih ? `${dipilih.dudi_nama}${dipilih.dudi_alamat ? ` — ${dipilih.dudi_alamat}` : ''}` : '-'), true),
        !edit && dudiAcuan && __pklField(asal ? `Salin ke siswa di DU/DI yang sama (${tujuanSalin.length} dipilih)` : `Salin juga ke siswa lain di DU/DI yang sama (opsional, ${tujuanSalin.length} dipilih)`,
          serumpun.length === 0
            ? h('p', { className: K.teksKecil }, 'Tidak ada siswa bimbingan lain di DU/DI ini pada tanggal tersebut.')
            : h('div', { className: K.field },
              h('div', { className: K.daftarSiswa },
                serumpun.map((p) => h('label', { key: p.id, className: K.barisSiswa },
                  h('input', { type: 'checkbox', checked: !!salin[p.id], onChange: (e) => { const v = e.target.checked; setSalin((x) => ({ ...x, [p.id]: v })); } }),
                  h('span', null, p.siswa_nama),
                  h('span', { className: K.teksKecil }, p.kelas_nama)))),
              h('div', { className: K.bar },
                h('p', { className: K.teksKecil }, 'Isian (termasuk kondisi siswa dan foto) disalin sama untuk tiap siswa. Setelah disimpan, jurnal tiap siswa bisa diubah sendiri-sendiri.'),
                h('button', { onClick: () => setSalin(semuaDipilih ? {} : Object.fromEntries(serumpun.map((p) => [p.id, true]))), className: K.btnKecil },
                  semuaDipilih ? 'Batal pilih semua' : 'Pilih semua'))), true),
        __pklField('Jenis kegiatan', h('div', { className: K.toggleWrap },
          [['KUNJUNGAN', 'Kunjungan/Monitoring'], ['DARING', 'Daring'], ['SEKOLAH', 'Di Sekolah']].map(([v, l]) => h('button', {
            key: v, onClick: () => ubah('jenis', v), className: f.jenis === v ? K.toggleOn : K.toggleOff,
          }, l))), true),
        teks('uraian', 'Uraian kegiatan / materi bimbingan *', 5000, 4),
        teks('kondisi_siswa', 'Kondisi / perkembangan siswa', 2000),
        teks('permasalahan', 'Permasalahan', 2000),
        teks('tindak_lanjut', 'Tindak lanjut', 2000),
        __pklField('Foto bukti (opsional, maks 3, JPG/PNG/WEBP ≤ 2 MB)', h('div', { className: K.fotoWrap },
          (f.foto || []).map((src) => h('div', { key: src, className: K.fotoItem },
            h('img', { src, alt: 'Foto bimbingan', className: K.foto }),
            h('button', { onClick: () => setF((x) => ({ ...x, foto: x.foto.filter((y) => y !== src) })), className: K.btnMerah }, 'Lepas'))),
          (f.foto || []).length < 3 && h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', disabled: unggah, onChange: pilihFoto, className: K.teksKecil }),
          unggah && h('span', { className: K.teksKecil }, 'Mengunggah...')), true)),
      h('div', { className: K.bar },
        h('p', { className: K.teksKecil }, 'Kehadiran harian siswa PKL dicatat oleh tempat PKL, tidak di jurnal ini.'),
        h('button', { disabled: !ok, onClick: simpan, className: ok ? K.btnHijau : K.btnMati },
          proses ? 'Menyimpan...' : (!edit && jumlahSimpan > 1 ? `Simpan Jurnal (${jumlahSimpan} siswa)` : 'Simpan Jurnal')))));
}

// Tab GURU: Siswa Bimbingan.
function __pklTabSiswa({ ktx, onTulis }) {
  const K = __pklK;
  const h = __pklH;
  const hariIni = __pklIso(new Date());
  const [items, setItems] = ue.useState(null);
  const [galat, setGalat] = ue.useState('');
  const [pesan, setPesan] = ue.useState('');
  const [form, setForm] = ue.useState(false);
  const [aksi, setAksi] = ue.useState(null);
  const muat = ue.useCallback(async () => {
    setGalat('');
    try {
      const r = await __pklGet('pkl_penempatan_list', `&guru=${encodeURIComponent(ktx.uid)}`);
      setItems((r.items || []).filter((p) => p.milik));
    } catch (e) {
      setGalat(e.message);
      setItems([]);
    }
  }, [ktx.uid]);
  ue.useEffect(() => { muat(); }, [muat]);
  const selesai = (msg) => { setPesan(msg); setForm(false); setAksi(null); muat(); };
  const hapus = async (p) => {
    if (!window.confirm(`Hapus penempatan ${p.siswa_nama} di ${p.dudi_nama}?`)) return;
    try { await __pklPost('pkl_penempatan_hapus', { id: p.id }); selesai('Penempatan dihapus.'); } catch (e) { setGalat(e.message); }
  };
  const urut = (items || []).slice().sort((x, y) => (x.status === 'SELESAI') - (y.status === 'SELESAI') || String(x.siswa_nama).localeCompare(String(y.siswa_nama)));
  return h('div', { className: K.wrap },
    pesan && h('p', { className: K.infoHijau }, pesan),
    h(__pklGalat, { pesan: galat }),
    h('div', { className: K.bar },
      h('p', { className: K.judulKecil }, `Siswa Bimbingan (${(items || []).length})`),
      h('div', { className: K.barKiri },
        h('button', { onClick: muat, className: K.btnPutih }, h(po, { size: 13 }), 'Perbarui'),
        ktx.is_pembimbing && !form && h('button', { onClick: () => { setForm(true); setAksi(null); setPesan(''); }, className: K.btnHijau }, h(es, { size: 14 }), 'Tambah Siswa Bimbingan'))),
    form && h(__pklFormPenempatan, { onSelesai: (msg, tetapBuka) => { setPesan(msg); muat(); if (!tetapBuka) setForm(false); }, onBatal: () => setForm(false) }),
    aksi && aksi.jenis === 'ubah' && h(__pklFormPenempatan, { key: aksi.p.id, edit: aksi.p, onSelesai: (msg) => selesai(msg), onBatal: () => setAksi(null) }),
    items === null ? h(__pklMemuat, null)
      : urut.length === 0 ? h('p', { className: K.kosong }, ktx.is_pembimbing ? 'Belum ada siswa bimbingan. Klik "Tambah Siswa Bimbingan".' : 'Belum ada siswa bimbingan.')
        : h('div', { className: K.gridKartu }, urut.map((p) => h(__pklKartuPenempatan, { key: p.id, p },
          p.tanggal_mulai <= hariIni && h('button', { onClick: () => onTulis(p), className: K.btnKecilHijau }, h(es, { size: 12 }), 'Tulis Jurnal'),
          h('button', { onClick: () => { setAksi({ jenis: 'ubah', p }); setForm(false); }, className: K.btnKecil }, 'Ubah'),
          p.status !== 'SELESAI' && h('button', { onClick: () => setAksi({ jenis: 'tutup', p }), className: K.btnKecil }, 'Tutup'),
          p.jumlah_jurnal === 0 && h('button', { onClick: () => hapus(p), className: K.btnMerah }, 'Hapus'),
          h('button', { onClick: () => __pklCetak(`pkl_cetak_kartu&siswa=${encodeURIComponent(p.siswa_id)}`), className: K.btnKecil }, h(Ss, { size: 12 }), 'Kartu'),
          aksi && aksi.jenis === 'tutup' && aksi.p.id === p.id && h(__pklFormTutup, { p, onSelesai: selesai, onBatal: () => setAksi(null) })))));
}

// Tab GURU: Jurnal Bimbingan.
function __pklTabJurnal({ ktx, awal, onAwalDipakai }) {
  const K = __pklK;
  const h = __pklH;
  const hariIni = __pklIso(new Date());
  const [bulan, setBulan] = ue.useState(hariIni.slice(0, 7));
  const [siswa, setSiswa] = ue.useState('');
  const [penempatan, setPenempatan] = ue.useState([]);
  const [items, setItems] = ue.useState(null);
  const [galat, setGalat] = ue.useState('');
  const [pesan, setPesan] = ue.useState('');
  const [form, setForm] = ue.useState(null);

  ue.useEffect(() => {
    __pklGet('pkl_penempatan_list', `&guru=${encodeURIComponent(ktx.uid)}`)
      .then((r) => setPenempatan((r.items || []).filter((p) => p.milik)))
      .catch((e) => setGalat(e.message));
  }, [ktx.uid]);
  const muat = ue.useCallback(async () => {
    setGalat('');
    try {
      const q = `&guru=${encodeURIComponent(ktx.uid)}&bulan=${bulan}${siswa ? `&siswa=${encodeURIComponent(siswa)}` : ''}`;
      const r = await __pklGet('pkl_jurnal_list', q);
      setItems(r.items || []);
    } catch (e) {
      setGalat(e.message);
      setItems([]);
    }
  }, [ktx.uid, bulan, siswa]);
  ue.useEffect(() => { muat(); }, [muat]);
  ue.useEffect(() => {
    if (!awal) return;
    const tgl = hariIni > awal.tanggal_selesai ? awal.tanggal_selesai : hariIni;
    setForm({ penempatan_id: awal.id, tanggal: tgl });
    setPesan('');
    onAwalDipakai();
  }, [awal]);

  const hapus = async (j) => {
    if (!window.confirm(`Hapus jurnal ${__pklTgl(j.tanggal)} untuk ${j.siswa_nama}?`)) return;
    try { await __pklPost('pkl_jurnal_hapus', { id: j.id }); setPesan('Jurnal dihapus.'); muat(); } catch (e) { setGalat(e.message); }
  };
  const siswaUnik = [];
  penempatan.forEach((p) => { if (!siswaUnik.some((s) => s.id === p.siswa_id)) siswaUnik.push({ id: p.siswa_id, nama: p.siswa_nama }); });

  return h('div', { className: K.wrap },
    pesan && h('p', { className: K.infoHijau }, pesan),
    h(__pklGalat, { pesan: galat }),
    h('div', { className: K.bar },
      h('div', { className: K.barKiri },
        h('input', { type: 'month', className: K.inputTanggal, value: bulan, max: hariIni.slice(0, 7), onChange: (e) => setBulan(e.target.value || hariIni.slice(0, 7)) }),
        h('select', { className: K.select, value: siswa, onChange: (e) => setSiswa(e.target.value) },
          h('option', { value: '' }, 'Semua siswa'),
          siswaUnik.map((s) => h('option', { key: s.id, value: s.id }, s.nama)))),
      !form && h('button', { onClick: () => { setForm({}); setPesan(''); }, className: K.btnHijau }, h(es, { size: 14 }), 'Tambah Jurnal')),
    form && h(__pklFormJurnal, {
      key: form.id || (form.salinDari ? `salin-${form.salinDari.id}` : `baru-${form.penempatan_id || ''}`),
      awal: form,
      penempatan,
      onSelesai: (msg, tgl) => { setPesan(msg); setForm(null); if (tgl && tgl.slice(0, 7) !== bulan) setBulan(tgl.slice(0, 7)); else muat(); },
      onBatal: () => setForm(null),
    }),
    items === null ? h(__pklMemuat, null)
      : h(__pklDaftarJurnal, {
        items,
        onUbah: (j) => { setForm({ ...j }); setPesan(''); },
        onSalin: (j) => {
          setForm({
            salinDari: j, tanggal: j.tanggal, jam_mulai: j.jam_mulai, jam_selesai: j.jam_selesai, jenis: j.jenis, uraian: j.uraian,
            kondisi_siswa: j.kondisi_siswa || '', permasalahan: j.permasalahan || '', tindak_lanjut: j.tindak_lanjut || '', foto: j.foto || [],
          });
          setPesan('');
        },
        onHapus: hapus,
        kosong: 'Belum ada jurnal bimbingan pada bulan ini.',
      }));
}

// Tab wali kelas XII: Siswa PKL Kelas Saya (hanya baca).
function __pklTabWali({ ktx }) {
  const K = __pklK;
  const h = __pklH;
  const [data, setData] = ue.useState(null);
  const [galat, setGalat] = ue.useState('');
  const [buka, setBuka] = ue.useState('');
  ue.useEffect(() => {
    __pklGet('pkl_penempatan_list').then(setData).catch((e) => { setGalat(e.message); setData({ items: [] }); });
  }, []);
  if (!data) return h(__pklMemuat, null);
  const items = (data.items || []).filter((p) => p.kelas_wali);
  const belum = data.belum_ditempatkan || [];
  return h('div', { className: K.wrap },
    h(__pklGalat, { pesan: galat }),
    h('p', { className: K.teks }, `Kelas perwalian: ${ktx.kelas_wali ? ktx.kelas_wali.nama : '-'} · ${items.length} penempatan · ${belum.length} siswa belum punya pembimbing`),
    belum.length > 0 && h('div', { className: K.infoKuning },
      h('p', null, 'Belum punya pembimbing PKL:'),
      h('p', null, belum.map((s) => s.nama).join(', '))),
    items.length === 0 ? h('p', { className: K.kosong }, 'Belum ada siswa kelas ini yang ditempatkan.')
      : h('div', { className: K.gridKartu }, items.map((p) => h(__pklKartuPenempatan, { key: p.id, p, tampilGuru: true },
        h('button', { onClick: () => setBuka(buka === p.id ? '' : p.id), className: K.btnKecil }, buka === p.id ? 'Sembunyikan Jurnal' : 'Lihat Jurnal'),
        h('button', { onClick: () => __pklCetak(`pkl_cetak_kartu&siswa=${encodeURIComponent(p.siswa_id)}`), className: K.btnKecil }, h(Ss, { size: 12 }), 'Kartu'),
        buka === p.id && h(__pklJurnalPenempatan, { p, bolehHapus: false })))));
}

// Tab Kepala Sekolah/Admin: Pantau.
function __pklTabPantau({ ktx }) {
  const K = __pklK;
  const h = __pklH;
  const hariIni = __pklIso(new Date());
  const guruSyarat = (ktx.guru || []).filter((g) => g.memenuhi_syarat);
  const [guru, setGuru] = ue.useState('');
  const [status, setStatus] = ue.useState('');
  const [cari, setCari] = ue.useState('');
  const [bulan, setBulan] = ue.useState(hariIni.slice(0, 7));
  const [data, setData] = ue.useState(null);
  const [jurnalBulan, setJurnalBulan] = ue.useState([]);
  const [galat, setGalat] = ue.useState('');
  const [pesan, setPesan] = ue.useState('');
  const [buka, setBuka] = ue.useState('');
  const [aksi, setAksi] = ue.useState(null);
  const [form, setForm] = ue.useState(false);
  const [lihatBelum, setLihatBelum] = ue.useState(false);

  const muat = ue.useCallback(async () => {
    setGalat('');
    try {
      const q = `${guru ? `&guru=${encodeURIComponent(guru)}` : ''}${status ? `&status=${status}` : ''}`;
      const [r, j] = await Promise.all([__pklGet('pkl_penempatan_list', q), __pklGet('pkl_jurnal_list', `&bulan=${bulan}`)]);
      setData(r);
      setJurnalBulan(j.items || []);
    } catch (e) {
      setGalat(e.message);
      setData({ items: [] });
    }
  }, [guru, status, bulan]);
  ue.useEffect(() => { muat(); }, [muat]);
  const selesai = (msg) => { setPesan(msg); setAksi(null); setForm(false); muat(); };
  const hapus = async (p) => {
    if (!window.confirm(`Hapus penempatan ${p.siswa_nama} di ${p.dudi_nama}?`)) return;
    try { await __pklPost('pkl_penempatan_hapus', { id: p.id }); selesai('Penempatan dihapus.'); } catch (e) { setGalat(e.message); }
  };

  if (!data) return h(__pklMemuat, null);
  const q = cari.trim().toLowerCase();
  const items = (data.items || []).filter((p) => !q || `${p.siswa_nama} ${p.kelas_nama} ${p.dudi_nama} ${p.guru_nama}`.toLowerCase().includes(q));
  const belum = data.belum_ditempatkan || [];

  // Ringkasan per guru: siswa aktif, jurnal bulan dipilih, bimbingan terakhir
  const ring = {};
  (data.items || []).forEach((p) => {
    const r = ring[p.guru_id] || (ring[p.guru_id] = { nama: p.guru_nama, aktif: 0, jurnal: 0, terakhir: '' });
    if (p.status === 'AKTIF') r.aktif += 1;
    if (p.jurnal_terakhir && p.jurnal_terakhir > r.terakhir) r.terakhir = p.jurnal_terakhir;
  });
  jurnalBulan.forEach((j) => {
    const r = ring[j.guru_id] || (ring[j.guru_id] = { nama: j.guru_nama, aktif: 0, jurnal: 0, terakhir: '' });
    r.jurnal += 1;
    if (j.tanggal > r.terakhir) r.terakhir = j.tanggal;
  });
  const ringkasan = Object.keys(ring).map((k) => ring[k]).sort((x, y) => String(x.nama).localeCompare(String(y.nama)));

  return h('div', { className: K.wrap },
    pesan && h('p', { className: K.infoHijau }, pesan),
    h(__pklGalat, { pesan: galat }),
    h('div', { className: K.card },
      h('div', { className: K.cardHead },
        h('p', { className: K.judulKecil }, 'Ringkasan per Guru Pembimbing'),
        h('div', { className: K.barKiri },
          h('span', { className: K.teksKecil }, 'Jurnal bulan'),
          h('input', { type: 'month', className: K.inputTanggal, value: bulan, max: hariIni.slice(0, 7), onChange: (e) => setBulan(e.target.value || hariIni.slice(0, 7)) }))),
      ringkasan.length === 0 ? h('p', { className: K.kosong }, 'Belum ada data pembimbingan.')
        : h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
          h('thead', { className: K.thead }, h('tr', null,
            h('th', { className: K.th }, 'Guru'), h('th', { className: K.th }, 'Siswa aktif'),
            h('th', { className: K.th }, 'Jurnal bulan ini'), h('th', { className: K.th }, 'Bimbingan terakhir'))),
          h('tbody', { className: K.tbody }, ringkasan.map((r) => h('tr', { key: r.nama },
            h('td', { className: K.tdNama }, r.nama), h('td', { className: K.td }, String(r.aktif)),
            h('td', { className: K.td }, String(r.jurnal)), h('td', { className: K.td }, r.terakhir ? __pklTgl(r.terakhir) : '-'))))))),
    belum.length > 0 && h('div', { className: K.infoKuning },
      h('div', { className: K.bar },
        h('span', null, `${belum.length} siswa kelas XII aktif belum punya pembimbing PKL.`),
        h('button', { onClick: () => setLihatBelum(!lihatBelum), className: K.btnKecil }, lihatBelum ? 'Sembunyikan' : 'Lihat')),
      lihatBelum && h('p', null, belum.map((s) => `${s.nama} (${s.kelas_nama})`).join(', '))),
    h('div', { className: K.bar },
      h('div', { className: K.barKiri },
        h('select', { className: K.select, value: guru, onChange: (e) => setGuru(e.target.value) },
          h('option', { value: '' }, 'Semua guru'),
          (ktx.guru || []).map((g) => h('option', { key: g.uid, value: g.uid }, g.nama))),
        h('select', { className: K.select, value: status, onChange: (e) => setStatus(e.target.value) },
          h('option', { value: '' }, 'Semua status'),
          h('option', { value: 'AKTIF' }, 'Aktif'), h('option', { value: 'AKAN_DATANG' }, 'Akan datang'), h('option', { value: 'SELESAI' }, 'Selesai')),
        h('input', { className: K.inputTanggal, placeholder: 'Cari siswa/kelas/DU/DI...', value: cari, onChange: (e) => setCari(e.target.value) }),
        h('button', { onClick: muat, className: K.btnPutih }, h(po, { size: 13 }), 'Perbarui')),
      ktx.is_admin && !form && h('button', { onClick: () => { setForm(true); setPesan(''); }, className: K.btnHijau }, h(es, { size: 14 }), 'Tambah Penempatan')),
    form && h(__pklFormPenempatan, { guruPilihan: guruSyarat, onSelesai: (msg, tetapBuka) => { setPesan(msg); muat(); if (!tetapBuka) setForm(false); }, onBatal: () => setForm(false) }),
    aksi && aksi.jenis === 'ubah' && h(__pklFormPenempatan, { key: aksi.p.id, edit: aksi.p, onSelesai: selesai, onBatal: () => setAksi(null) }),
    h('p', { className: K.judulKecil }, `Penempatan (${items.length})`),
    items.length === 0 ? h('p', { className: K.kosong }, 'Tidak ada penempatan.')
      : h('div', { className: K.gridKartu }, items.map((p) => h(__pklKartuPenempatan, { key: p.id, p, tampilGuru: true },
        h('button', { onClick: () => setBuka(buka === p.id ? '' : p.id), className: K.btnKecil }, buka === p.id ? 'Sembunyikan Jurnal' : 'Lihat Jurnal'),
        h('button', { onClick: () => __pklCetak(`pkl_cetak_kartu&siswa=${encodeURIComponent(p.siswa_id)}`), className: K.btnKecil }, h(Ss, { size: 12 }), 'Kartu'),
        ktx.is_admin && h('button', { onClick: () => { setAksi({ jenis: 'ubah', p }); setForm(false); }, className: K.btnKecil }, 'Ubah'),
        ktx.is_admin && p.tanggal_selesai > p.tanggal_mulai && p.status !== 'SELESAI' && h('button', { onClick: () => setAksi({ jenis: 'alih', p }), className: K.btnKecil }, 'Alih Pembimbing'),
        ktx.is_admin && p.status !== 'SELESAI' && h('button', { onClick: () => setAksi({ jenis: 'tutup', p }), className: K.btnKecil }, 'Tutup'),
        ktx.is_admin && p.jumlah_jurnal === 0 && h('button', { onClick: () => hapus(p), className: K.btnMerah }, 'Hapus'),
        aksi && aksi.p.id === p.id && aksi.jenis === 'alih' && h(__pklFormAlih, { p, guruPilihan: guruSyarat, onSelesai: selesai, onBatal: () => setAksi(null) }),
        aksi && aksi.p.id === p.id && aksi.jenis === 'tutup' && h(__pklFormTutup, { p, onSelesai: selesai, onBatal: () => setAksi(null) }),
        buka === p.id && h(__pklJurnalPenempatan, { p, bolehHapus: ktx.is_admin })))));
}

// Tab ADMIN: master DU/DI.
function __pklTabDudi() {
  const K = __pklK;
  const h = __pklH;
  const [items, setItems] = ue.useState(null);
  const [cari, setCari] = ue.useState('');
  const [galat, setGalat] = ue.useState('');
  const [pesan, setPesan] = ue.useState('');
  const [form, setForm] = ue.useState(null);
  const muat = ue.useCallback(() => {
    setGalat('');
    __pklGet('pkl_dudi_list').then((r) => setItems(r.items || [])).catch((e) => { setGalat(e.message); setItems([]); });
  }, []);
  ue.useEffect(() => { muat(); }, [muat]);
  const hapus = async (d) => {
    if (!window.confirm(`Hapus DU/DI ${d.nama}?`)) return;
    try { await __pklPost('pkl_dudi_hapus', { id: d.id }); setPesan('DU/DI dihapus.'); muat(); } catch (e) { setGalat(e.message); }
  };
  if (!items) return h(__pklMemuat, null);
  const q = cari.trim().toLowerCase();
  const tampil = items.filter((d) => !q || `${d.nama} ${d.alamat || ''} ${d.bidang_usaha || ''}`.toLowerCase().includes(q));
  return h('div', { className: K.wrap },
    pesan && h('p', { className: K.infoHijau }, pesan),
    h(__pklGalat, { pesan: galat }),
    h('div', { className: K.bar },
      h('input', { className: K.inputTanggal, placeholder: 'Cari DU/DI...', value: cari, onChange: (e) => setCari(e.target.value) }),
      !form && h('button', { onClick: () => { setForm({}); setPesan(''); }, className: K.btnHijau }, h(es, { size: 14 }), 'Tambah DU/DI')),
    form && h(__pklFormDudi, {
      key: form.id || 'baru', awal: form,
      onTersimpan: () => { setForm(null); setPesan('DU/DI tersimpan.'); muat(); },
      onBatal: () => setForm(null),
    }),
    tampil.length === 0 ? h('p', { className: K.kosong }, 'Belum ada DU/DI.')
      : h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
        h('thead', { className: K.thead }, h('tr', null,
          h('th', { className: K.th }, 'DU/DI'), h('th', { className: K.th }, 'Alamat'), h('th', { className: K.th }, 'Pembimbing Industri'),
          h('th', { className: K.th }, 'Penempatan'), h('th', { className: K.th }, ''))),
        h('tbody', { className: K.tbody }, tampil.map((d) => h('tr', { key: d.id },
          h('td', { className: K.tdNama }, d.nama, d.bidang_usaha && h('p', { className: K.teksKecil }, d.bidang_usaha)),
          h('td', { className: K.td }, d.alamat || '-'),
          h('td', { className: K.td }, d.pembimbing_nama || '-', d.pembimbing_telp && h('p', { className: K.teksKecil }, d.pembimbing_telp)),
          h('td', { className: K.td }, `${d.jumlah_penempatan_aktif} aktif / ${d.jumlah_penempatan} total`),
          h('td', { className: K.td }, h('div', { className: K.barKiri },
            h('button', { onClick: () => { setForm({ ...d }); setPesan(''); }, className: K.btnKecil }, 'Ubah'),
            d.bisa_hapus && h('button', { onClick: () => hapus(d), className: K.btnMerah }, 'Hapus')))))))));
}

// Tab Cetak: rekap bulanan guru + kartu bimbingan siswa.
function __pklTabCetak({ ktx }) {
  const K = __pklK;
  const h = __pklH;
  const hariIni = __pklIso(new Date());
  const pemantau = ktx.is_admin || ktx.is_kepsek;
  const [bulan, setBulan] = ue.useState(hariIni.slice(0, 7));
  const [guru, setGuru] = ue.useState(pemantau ? '' : ktx.uid);
  const [foto, setFoto] = ue.useState(false);
  const [siswa, setSiswa] = ue.useState('');
  const [daftar, setDaftar] = ue.useState([]);
  const [galat, setGalat] = ue.useState('');
  ue.useEffect(() => {
    __pklGet('pkl_penempatan_list').then((r) => {
      const unik = [];
      (r.items || []).forEach((p) => { if (!unik.some((s) => s.id === p.siswa_id)) unik.push({ id: p.siswa_id, nama: p.siswa_nama, kelas: p.kelas_nama }); });
      unik.sort((x, y) => String(x.nama).localeCompare(String(y.nama)));
      setDaftar(unik);
    }).catch((e) => setGalat(e.message));
  }, []);
  const bisaRekap = ktx.peran === 'GURU' || guru;
  return h('div', { className: K.wrap },
    h(__pklGalat, { pesan: galat }),
    h('div', { className: K.gridKartu },
      h('div', { className: K.kartu },
        h('p', { className: K.subjudul }, 'Rekap Bulanan Bimbingan'),
        h('p', { className: K.teks }, 'Dokumen A4 ber-kop berisi seluruh kegiatan bimbingan satu guru dalam satu bulan, untuk lampiran e-Kinerja.'),
        pemantau && __pklField('Guru pembimbing', h('select', { className: K.select, value: guru, onChange: (e) => setGuru(e.target.value) },
          h('option', { value: '' }, '-- Pilih guru --'),
          (ktx.guru || []).map((g) => h('option', { key: g.uid, value: g.uid }, g.nama)))),
        __pklField('Bulan', h('input', { type: 'month', className: K.inputTanggal, value: bulan, max: hariIni.slice(0, 7), onChange: (e) => setBulan(e.target.value || hariIni.slice(0, 7)) })),
        h('label', { className: K.checkLabel }, h('input', { type: 'checkbox', checked: foto, onChange: (e) => setFoto(e.target.checked) }), 'Lampirkan foto kegiatan'),
        h('button', {
          disabled: !bisaRekap,
          onClick: () => __pklCetak(`pkl_cetak_rekap&bulan=${bulan}${pemantau ? `&guru=${encodeURIComponent(guru)}` : ''}${foto ? '&foto=1' : ''}`),
          className: bisaRekap ? K.btnHijau : K.btnMati,
        }, h(Ss, { size: 14 }), 'Buka Rekap')),
      h('div', { className: K.kartu },
        h('p', { className: K.subjudul }, 'Kartu Bimbingan Siswa'),
        h('p', { className: K.teks }, 'Riwayat tempat PKL dan seluruh bimbingan satu siswa, lintas pembimbing.'),
        __pklField('Siswa', h('select', { className: K.select, value: siswa, onChange: (e) => setSiswa(e.target.value) },
          h('option', { value: '' }, daftar.length ? '-- Pilih siswa --' : 'Belum ada siswa'),
          daftar.map((s) => h('option', { key: s.id, value: s.id }, `${s.nama} (${s.kelas})`)))),
        h('button', {
          disabled: !siswa,
          onClick: () => __pklCetak(`pkl_cetak_kartu&siswa=${encodeURIComponent(siswa)}`),
          className: siswa ? K.btnHijau : K.btnMati,
        }, h(Ss, { size: 14 }), 'Buka Kartu'))));
}

// Halaman utama: tab sesuai peran (pkl_konteks).
function __pklHalaman() {
  const K = __pklK;
  const h = __pklH;
  const [ktx, setKtx] = ue.useState(null);
  const [punya, setPunya] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const [tab, setTab] = ue.useState('');
  const [tulis, setTulis] = ue.useState(null);
  ue.useEffect(() => {
    let batal = false;
    (async () => {
      try {
        const k = await __pklGet('pkl_konteks');
        let ada = false;
        if (k.peran === 'GURU' && !k.is_pembimbing) {
          const r = await __pklGet('pkl_penempatan_list', `&guru=${encodeURIComponent(k.uid)}`);
          ada = (r.items || []).some((p) => p.milik);
        }
        if (!batal) { setKtx(k); setPunya(ada); }
      } catch (e) {
        if (!batal) setGalat(e.message);
      }
    })();
    return () => { batal = true; };
  }, []);

  const tabs = [];
  if (ktx) {
    if (ktx.peran === 'GURU' && (ktx.is_pembimbing || punya)) tabs.push(['siswa', 'Siswa Bimbingan'], ['jurnal', 'Jurnal Bimbingan']);
    if (ktx.is_wali_xii) tabs.push(['wali', 'Siswa PKL Kelas Saya']);
    if (ktx.is_admin || ktx.is_kepsek) tabs.push(['pantau', 'Pantau']);
    if (ktx.is_admin) tabs.push(['dudi', 'DU/DI']);
    if (tabs.length) tabs.push(['cetak', 'Cetak']);
  }
  const aktif = tabs.some((t) => t[0] === tab) ? tab : (tabs[0] ? tabs[0][0] : '');

  let isi;
  if (galat) {
    isi = h(__pklGalat, { pesan: galat });
  } else if (!ktx) {
    isi = h(__pklMemuat, null);
  } else if (!tabs.length) {
    isi = h('div', { className: K.infoKuning }, 'Menu ini untuk guru dengan penugasan mapel kelas XII. Lengkapi penugasan mapel di Profil bila Anda mengajar kelas XII.');
  } else {
    isi = h('div', { className: K.wrap },
      h('div', { className: K.toggleWrap }, tabs.map(([id, label]) => h('button', {
        key: id, onClick: () => setTab(id), className: aktif === id ? K.toggleOn : K.toggleOff,
      }, label))),
      aktif === 'siswa' && h(__pklTabSiswa, { ktx, onTulis: (p) => { setTulis(p); setTab('jurnal'); } }),
      aktif === 'jurnal' && h(__pklTabJurnal, { ktx, awal: tulis, onAwalDipakai: () => setTulis(null) }),
      aktif === 'wali' && h(__pklTabWali, { ktx }),
      aktif === 'pantau' && h(__pklTabPantau, { ktx }),
      aktif === 'dudi' && h(__pklTabDudi, null),
      aktif === 'cetak' && h(__pklTabCetak, { ktx }));
  }

  return h('div', { className: K.halaman },
    h(zi, {
      title: 'Bimbingan PKL',
      description: 'Jurnal pembimbingan siswa kelas XII selama Praktik Kerja Lapangan: siswa bimbingan, tempat PKL (DU/DI), dan kegiatan bimbingan.',
      category: 'Akademik',
      color: 'emerald',
      illustration: h(dW, { size: 70, className: 'text-white/80' }),
    }),
    isi);
}
/* eslint-enable no-undef */

const FUNGSI = [
  __pklH, __pklJson, __pklGet, __pklPost, __pklIso, __pklTgl, __pklCetak, __pklGalat, __pklMemuat, __pklField,
  __pklBadgeStatus, __pklKartuPenempatan, __pklDaftarJurnal, __pklJurnalPenempatan, __pklFormDudi, __pklPilihDudi,
  __pklFormPenempatan, __pklFormTutup, __pklFormAlih, __pklFormJurnal, __pklTabSiswa, __pklTabJurnal, __pklTabWali,
  __pklTabPantau, __pklTabDudi, __pklTabCetak, __pklHalaman,
];
const KOMPONEN_SRC = `const __pklK=${JSON.stringify(KELAS)};${FUNGSI.map((f) => f.toString()).join('')}`;

const patches = [
  {
    id: 'P0 komponen Bimbingan PKL',
    from: 'const __dspK=',
    to: `${KOMPONEN_SRC}const __dspK=`,
  },
  {
    id: 'P1 menu Akademik Bimbingan PKL',
    from: 'roles:["GURU","KEPALA_SEKOLAH"]},{id:"assessment"',
    to: 'roles:["GURU","KEPALA_SEKOLAH"]},{id:"bimbingan-pkl",label:"Bimbingan PKL",icon:a.jsx(dW,{size:20}),roles:["GURU","KEPALA_SEKOLAH","ADMIN"]},{id:"assessment"',
  },
  {
    id: 'P2 router halaman bimbingan-pkl',
    from: 'e==="dispensasi"&&a.jsx(__dspHalaman,{user:o}),',
    to: 'e==="dispensasi"&&a.jsx(__dspHalaman,{user:o}),e==="bimbingan-pkl"&&a.jsx(__pklHalaman,{}),',
  },
  {
    id: 'P3 daftar halaman dikenal',
    from: '"portal-nilai","dispensasi","kepegawaian-gtk"',
    to: '"portal-nilai","dispensasi","bimbingan-pkl","kepegawaian-gtk"',
  },
];

// ---------------------------------------------------------------------------
const css = fs.readFileSync(cssPath, 'utf8');
const cssEscape = (c) => c.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
const kelas = new Set(KELAS_PATCH);
Object.values(KELAS).forEach((v) => v.split(/\s+/).filter(Boolean).forEach((c) => kelas.add(c)));
for (const m of FUNGSI.map((f) => f.toString()).join('').matchAll(/className:\s*(['"`])([^'"`]*?)\1/g)) {
  m[2].split(/\s+/).filter((c) => c && !c.includes('${')).forEach((c) => kelas.add(c));
}
const hilang = [...kelas].filter((c) => {
  const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
  return !re.test(css);
});
if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));
console.log(`OK  ${kelas.size} class Tailwind tersedia di CSS bundle`);

for (const nama of ['__pklK', '__pklHalaman', '"bimbingan-pkl"']) {
  if (src.includes(nama)) fail(`${nama} sudah ada di ${SRC_NAME}.`);
}
// Identifier bundle yang dipakai komponen harus ada di scope modul
for (const id of ['dW', 'Z1', 'po', 'Ss', 'es']) {
  if (!new RegExp(`[,;\\s]${id}=Nt\\("`).test(src)) fail(`ikon ${id} tidak ditemukan di ${SRC_NAME}.`);
}
if (!/[,;\s]zi=\(\{title:e,description:t,category:n,color:r="blue",illustration:s\}\)=>/.test(src)) fail(`komponen zi tidak ditemukan di ${SRC_NAME}.`);

let out = src;
for (const p of patches) {
  const count = out.split(p.from).length - 1;
  if (count !== 1) fail(`${p.id}: anchor ditemukan ${count} kali (harus 1).`);
  out = out.replace(p.from, () => p.to);
  console.log('OK  ' + p.id);
}

fs.writeFileSync(outPath, out, 'utf8');
console.log(`Ditulis assets/${OUT_NAME}`);

const html = fs.readFileSync(appHtmlPath, 'utf8');
if (html.includes(`./assets/${OUT_NAME}`)) {
  console.log('app.html sudah mereferensikan bundle patch.');
} else {
  const ref = [SRC_NAME, ...OUT_LAMA].map((n) => `./assets/${n}`).find((r) => html.split(r).length - 1 === 1);
  if (!ref) fail(`app.html tidak mereferensikan ${SRC_NAME} (atau keluaran lama patch ini) tepat 1 kali.`);
  fs.writeFileSync(appHtmlPath, html.replace(ref, `./assets/${OUT_NAME}`), 'utf8');
  console.log(`app.html diarahkan ke assets/${OUT_NAME}`);
}
