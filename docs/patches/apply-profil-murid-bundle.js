// Patch bundle frontend: Profil Murid 360° (biodata Dapodik, asesmen non-kognitif, rencana karir, ringkasan).
// Spesifikasi: docs/specs/profil-murid-360.md (§5). Catatan patch: docs/patches/profil-murid-360.md
//  M0 Komponen (__pmProfil, __pmDaftar, __pmImpor, __pmImporDapodik, __pmFormAsesmen, __pmTren) + peta class (__pmK),
//     disisipkan tepat sebelum komponen Dashboard Kelas Saya (dot).
//  M1 Dashboard Kelas Saya: per_siswa menyimpan student_id.
//  M2 Dashboard Kelas Saya: nama murid di tab "Per Siswa" membuka profil.
//  M3 Dashboard Kelas Saya: tab baru "👤 Profil Murid" (daftar + impor asesmen kelas perwalian).
//  M4 Sidebar BK: item "Profil Murid 360°" (bk-profil-murid) setelah "Pantau Nilai Kelas".
//  M5 Sidebar grup "Manajemen Data": item "Profil Murid 360°" untuk ADMIN/KEPALA_SEKOLAH.
//  M6 Router halaman: bk-profil-murid (daftar) dan profil-murid (halaman profil).
//  M7 Daftar halaman yang dikenal: bk-profil-murid, profil-murid.
//  M8 Data Siswa (TU/ADMIN): tombol "Impor Biodata Dapodik".
// Prasyarat: patch Hari Sekolah (apply-hari-sekolah-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-profil-murid-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-harisekolah1.js';
const OUT_NAME = 'index-CGWL69P7-profilmurid1.js';
const SRC_SHA256 = '7a2edf32de2296dbcefbe2dae41986dc38bf763cf83b90225510a5c0808b4263';
const CSS_NAME = 'index-CC1PBzaO.css';

function fail(msg) {
  console.error('GAGAL: ' + msg);
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Semua class Tailwind komponen ada di sini (divalidasi terhadap CSS bundle di bawah).
// ---------------------------------------------------------------------------
const BADGE = 'inline-block text-[10px] font-black px-2 py-0.5 rounded-full';
const TAB = 'px-4 py-2 rounded-xl text-xs font-black transition-all';
const KELAS = {
  halaman: 'max-w-6xl mx-auto space-y-8 pb-20',
  wrap: 'space-y-6',
  bar: 'flex flex-wrap items-center justify-between gap-3',
  barKiri: 'flex flex-wrap items-center gap-2',
  select: 'px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-600',
  input: 'px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600',
  btnPutih: 'flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-500 hover:bg-slate-50 transition-all',
  btnHijau: 'flex items-center gap-2 px-4 py-2 bg-emerald-600 border border-emerald-600 rounded-xl text-xs font-black text-white hover:bg-emerald-700 transition-all',
  btnMerah: 'flex items-center gap-2 px-4 py-2 bg-white border border-red-100 rounded-xl text-xs font-black text-red-600 hover:bg-red-50 transition-all',
  btnIndigo: 'flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 transition-colors shadow-sm whitespace-nowrap shrink-0',
  periode: 'text-xs font-black text-slate-400 uppercase tracking-widest',
  card: 'bg-white rounded-[2.5rem] border border-slate-100 overflow-hidden shadow-sm',
  cardHead: 'p-6 border-b border-slate-50 flex flex-wrap items-center justify-between gap-3',
  cardBody: 'p-6 space-y-4',
  judulKecil: 'text-xs font-black text-slate-400 uppercase tracking-widest',
  subjudul: 'text-lg font-black text-slate-800',
  teks: 'text-sm text-slate-500',
  teksKecil: 'text-[11px] font-bold text-slate-400',
  grid2: 'grid grid-cols-1 md:grid-cols-2 gap-4',
  grid4: 'grid grid-cols-2 md:grid-cols-4 gap-4',
  stat: 'bg-white rounded-3xl border border-slate-100 p-5',
  statLabel: 'text-[10px] font-black text-slate-400 uppercase tracking-widest',
  statNilai: 'text-2xl font-black text-slate-800 mt-0.5',
  statNilaiMerah: 'text-2xl font-black text-red-600 mt-0.5',
  badgeAbu: BADGE + ' bg-slate-100 text-slate-500',
  badgeMerah: BADGE + ' bg-red-50 text-red-700',
  badgeHijau: BADGE + ' bg-emerald-50 text-emerald-700',
  badgeKuning: BADGE + ' bg-amber-50 text-amber-700',
  badgeBiru: BADGE + ' bg-blue-50 text-blue-700',
  tabelWrap: 'overflow-x-auto',
  tabel: 'w-full text-left',
  thead: 'bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest',
  th: 'px-4 py-3 whitespace-nowrap',
  thTengah: 'px-3 py-3 text-center whitespace-nowrap',
  tbody: 'divide-y divide-slate-50',
  tr: 'hover:bg-slate-50/50 transition-colors',
  trMerah: 'bg-red-50',
  td: 'px-4 py-3 text-sm text-slate-600',
  tdTengah: 'px-3 py-3 text-center text-sm',
  tdNama: 'px-4 py-3 whitespace-nowrap',
  namaBtn: 'font-bold text-slate-700 hover:text-emerald-600 text-left',
  nisn: 'block text-[10px] font-bold text-slate-400',
  kosong: 'py-16 text-center text-slate-400 font-bold text-sm',
  kosongKecil: 'py-6 text-center text-slate-400 font-bold text-sm',
  spinnerWrap: 'py-20 text-center space-y-3',
  spinner: 'w-10 h-10 border-4 border-emerald-600 border-t-transparent rounded-full animate-spin mx-auto',
  spinnerTeks: 'text-slate-400 font-bold text-sm',
  galat: 'flex items-center gap-3 p-5 rounded-3xl border border-red-100 bg-red-50 text-red-700 text-sm font-bold',
  peringatan: 'p-4 rounded-2xl bg-amber-50 text-amber-700 text-sm font-bold',
  info: 'p-4 rounded-2xl bg-slate-50 text-sm text-slate-600',
  slot: 'p-6 rounded-3xl border border-dashed border-slate-200 bg-slate-50 text-sm font-bold text-slate-400 text-center',
  kv: 'grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2',
  kvLabel: 'block text-[10px] font-black text-slate-400 uppercase tracking-widest',
  kvNilai: 'block text-sm font-bold text-slate-700',
  kepala: 'bg-white rounded-[2.5rem] border border-slate-100 p-6 shadow-sm flex flex-wrap items-center gap-5',
  foto: 'w-20 h-20 rounded-2xl border border-slate-200',
  nama: 'text-2xl font-black text-slate-800',
  kepalaKanan: 'flex flex-wrap items-center gap-2 ml-auto',
  flex1: 'flex-1 min-w-0 space-y-1',
  tabWrap: 'flex flex-wrap bg-slate-100 p-1.5 rounded-2xl w-fit gap-1',
  tabOn: TAB + ' bg-white text-emerald-600 shadow-sm',
  tabOff: TAB + ' text-slate-400 hover:text-slate-600',
  barBaris: 'flex items-center gap-3',
  barLabel: 'w-28 shrink-0 text-xs font-black text-slate-600',
  barTrack: 'flex-1 h-3 bg-slate-100 rounded-full overflow-hidden',
  barIsi: 'h-full bg-emerald-500 rounded-full',
  barIsiBiru: 'h-full bg-blue-500 rounded-full',
  barAngka: 'w-12 text-right text-xs font-black text-slate-600',
  kode: 'text-4xl font-black text-emerald-600',
  chip: 'inline-block text-xs font-bold px-3 py-1 rounded-full bg-slate-100 text-slate-600 mr-2 mb-2',
  legenda: 'flex flex-wrap gap-x-4 gap-y-1 text-[11px] font-bold text-slate-500',
  legendaItem: 'flex items-center gap-1.5',
  titik: 'inline-block w-2.5 h-2.5 rounded-full',
  overlay: 'fixed inset-0 z-50 bg-slate-900/50 flex items-center justify-center p-4',
  modal: 'bg-white rounded-3xl shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto',
  modalHead: 'p-6 border-b border-slate-100 flex items-center justify-between gap-3',
  modalBody: 'p-6 space-y-4',
  modalFoot: 'p-6 border-t border-slate-100 flex flex-wrap justify-end gap-2',
  tutup: 'p-2 hover:bg-slate-100 rounded-full text-slate-500',
  label: 'block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1',
  field: 'w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-700',
  grid3: 'grid grid-cols-3 gap-3',
  daftarPesan: 'text-[11px] font-bold text-slate-500',
  daftarPesanMerah: 'text-[11px] font-bold text-red-600',
  ringkas: 'flex flex-wrap gap-2',
  ilustrasi: 'text-white/80',
};

// ---------------------------------------------------------------------------
// M0 Komponen. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas dari scope modul bundle:
//   a = jsx runtime, ue = React, K$ = URL api/index.php, zi = kartu judul halaman, Ci = avatar (src, name),
//   C_ = XLSX.read, Jl = XLSX.utils, QC = XLSX.writeFile,
//   ikon lucide: Tce (id-card), If (upload), Ti (download), Ss (printer), kp (arrow-left), Z1 (triangle-alert),
//   po (refresh-cw), fue (pencil), ar (trash-2), pi (x), Ui (search).
// __pmK = peta class Tailwind (KELAS di atas).
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */
function __pmH(t, p, ...c) {
  const { key, ...rest } = p || {};
  if (c.length) rest.children = c.length === 1 ? c[0] : c;
  return a.jsx(t, rest, key);
}

async function __pmJson(url, body) {
  const opsi = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
  const res = await fetch(url, opsi);
  let j = null;
  try { j = await res.json(); } catch (e) { j = null; }
  if (!res.ok) {
    const pesan = j && j.error ? j.error : `Permintaan gagal (HTTP ${res.status}).`;
    throw new Error(pesan === 'Unauthorized' ? 'Sesi berakhir, silakan login ulang.' : pesan);
  }
  return j;
}

function __pmAngka(v) {
  if (v === null || v === undefined || v === '') return '-';
  const n = Math.round(Number(v) * 10) / 10;
  return Number.isFinite(n) ? String(n).replace('.', ',') : '-';
}

function __pmTanggal(v) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v || ''));
  if (!m) return '-';
  const bln = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  return `${Number(m[3])} ${bln[Number(m[2]) - 1]} ${m[1]}`;
}

function __pmIsi(v) {
  return v === null || v === undefined || v === '' ? '-' : String(v);
}

// Buka halaman profil murid (id disimpan di window + sessionStorage agar tahan refresh)
function __pmBuka(studentId, asal, navigasi) {
  window.__pmSid = studentId;
  window.__pmAsal = asal;
  try { sessionStorage.setItem('simantap_pm_sid', studentId); sessionStorage.setItem('simantap_pm_asal', asal); } catch (e) { /* abaikan */ }
  if (typeof navigasi === 'function') navigasi('profil-murid');
}

// Baca sheet pertama file Excel/CSV menjadi array 2D string
async function __pmBacaFile(file) {
  const buf = await file.arrayBuffer();
  const wb = C_(new Uint8Array(buf), { type: 'array' });
  const ws = wb.Sheets[wb.SheetNames[0]];
  if (!ws) throw new Error('File tidak berisi sheet.');
  const rows = Jl.sheet_to_json(ws, { header: 1, raw: true, defval: '' }).map((r) => r.map((v) => {
    if (v === null || v === undefined || typeof v === 'boolean') return '';
    if (typeof v === 'number') return Number.isInteger(v) ? v.toFixed(0) : String(v);
    return String(v);
  }));
  while (rows.length && rows[rows.length - 1].every((v) => String(v).trim() === '')) rows.pop();
  if (!rows.length) throw new Error('File kosong.');
  return rows;
}

function __pmUnduh(aoa, namaFile, namaSheet) {
  const wb = Jl.book_new();
  Jl.book_append_sheet(wb, Jl.aoa_to_sheet(aoa), namaSheet || 'Data');
  QC(wb, namaFile);
}

// Modal impor generik: pilih file -> pratinjau -> simpan -> ringkasan
function __pmImpor({ judul, petunjuk, kirim, statusTulis, labelStatus, onTutup, onSelesai, kolomTambahan }) {
  const K = __pmK;
  const h = __pmH;
  const [tahap, setTahap] = ue.useState('pilih');
  const [file, setFile] = ue.useState(null);
  const [rows, setRows] = ue.useState(null);
  const [hasil, setHasil] = ue.useState(null);
  const [sibuk, setSibuk] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');

  const pratinjau = async (f) => {
    setGalat(''); setSibuk(true); setFile(f); setHasil(null);
    try {
      const r = await __pmBacaFile(f);
      setRows(r);
      const j = await kirim(r, 'pratinjau', f.name);
      setHasil(j); setTahap('pratinjau');
    } catch (e) { setGalat(e.message); } finally { setSibuk(false); }
  };
  const simpan = async () => {
    setGalat(''); setSibuk(true);
    try {
      const j = await kirim(rows, 'simpan', file ? file.name : '');
      setHasil(j); setTahap('selesai');
      if (onSelesai) onSelesai(j);
    } catch (e) { setGalat(e.message); } finally { setSibuk(false); }
  };
  const baris = hasil && Array.isArray(hasil.baris) ? hasil.baris : [];
  const jmlTulis = baris.filter((b) => statusTulis.includes(b.status)).length;
  const warna = (s) => (statusTulis.includes(s) ? K.badgeHijau : s === 'LEWATI' ? K.badgeAbu : s === 'TIDAK_DITEMUKAN' ? K.badgeKuning : K.badgeMerah);
  const unduhMasalah = () => {
    const masalah = baris.filter((b) => !statusTulis.includes(b.status));
    __pmUnduh([['Baris', 'NISN', 'Nama', 'Status', 'Keterangan'],
      ...masalah.map((b) => [b.baris, b.nisn || '', b.nama || '', labelStatus[b.status] || b.status, [...(b.pesan || []), ...(b.peringatan || [])].join('; ')])],
      'Laporan-Impor-Bermasalah.xlsx', 'Bermasalah');
  };

  const isiPilih = h('div', { className: K.modalBody },
    h('p', { className: K.teks }, petunjuk),
    h('input', { type: 'file', accept: '.xlsx,.xls,.csv', className: K.field, disabled: sibuk,
      onChange: (e) => { const f = e.target.files && e.target.files[0]; if (f) pratinjau(f); e.target.value = ''; } }),
    sibuk && h('p', { className: K.spinnerTeks }, 'Membaca file…'));

  const isiHasil = hasil && h('div', { className: K.modalBody },
    h('div', { className: K.ringkas },
      ...Object.entries(hasil.ringkasan || {}).map(([k, v]) => h('span', { key: k, className: K.chip }, `${(labelStatus[k.toUpperCase()] || k).replace(/_/g, ' ')}: ${v}`))),
    hasil.peringatan_file && h('div', { className: K.peringatan }, hasil.peringatan_file),
    hasil.kolom && h('p', { className: K.teksKecil },
      `Kolom dikenali: ${hasil.kolom.dikenali.length}. Kolom lain (disimpan sebagai data tambahan): ${hasil.kolom.lain.length}. `
      + (hasil.kolom.dibuang.length ? `Kolom dibuang (tidak disimpan): ${hasil.kolom.dibuang.join(', ')}.` : '')),
    tahap === 'selesai' && h('div', { className: K.info }, `Selesai. ${jmlTulis} baris disimpan.`),
    h('div', { className: K.tabelWrap },
      h('table', { className: K.tabel },
        h('thead', { className: K.thead }, h('tr', null,
          h('th', { className: K.th }, 'Baris'), h('th', { className: K.th }, 'NISN'), h('th', { className: K.th }, 'Nama'),
          kolomTambahan && h('th', { className: K.th }, 'SIMANTAP'),
          h('th', { className: K.th }, 'Status'), h('th', { className: K.th }, 'Keterangan'))),
        h('tbody', { className: K.tbody }, ...baris.map((b) => h('tr', { key: b.baris, className: K.tr },
          h('td', { className: K.td }, b.baris), h('td', { className: K.td }, b.nisn || '-'), h('td', { className: K.td }, b.nama || '-'),
          kolomTambahan && h('td', { className: K.td }, b.nama_simantap ? `${b.nama_simantap} (${b.kelas_simantap || '-'})` : '-'),
          h('td', { className: K.td }, h('span', { className: warna(b.status) }, labelStatus[b.status] || b.status)),
          h('td', { className: K.td },
            ...(b.pesan || []).map((p, i) => h('div', { key: 'p' + i, className: K.daftarPesanMerah }, p)),
            ...(b.peringatan || []).map((p, i) => h('div', { key: 'w' + i, className: K.daftarPesan }, p)))))))));

  return h('div', { className: K.overlay, onClick: (e) => { if (e.target === e.currentTarget && !sibuk) onTutup(); } },
    h('div', { className: K.modal },
      h('div', { className: K.modalHead },
        h('p', { className: K.subjudul }, judul),
        h('button', { type: 'button', className: K.tutup, onClick: onTutup, disabled: sibuk, 'aria-label': 'Tutup' }, a.jsx(pi, { size: 18 }))),
      galat && h('div', { className: K.modalBody }, h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat)),
      tahap === 'pilih' ? isiPilih : isiHasil,
      h('div', { className: K.modalFoot },
        tahap !== 'pilih' && baris.some((b) => !statusTulis.includes(b.status))
          && h('button', { type: 'button', className: K.btnPutih, onClick: unduhMasalah }, a.jsx(Ti, { size: 14 }), 'Unduh baris bermasalah'),
        tahap === 'pratinjau' && h('button', { type: 'button', className: K.btnPutih, disabled: sibuk, onClick: () => { setTahap('pilih'); setHasil(null); } }, 'Pilih file lain'),
        tahap === 'pratinjau' && h('button', { type: 'button', className: K.btnHijau, disabled: sibuk || jmlTulis === 0, onClick: simpan },
          sibuk ? 'Menyimpan…' : `Simpan ${jmlTulis} baris`),
        h('button', { type: 'button', className: K.btnPutih, disabled: sibuk, onClick: onTutup }, tahap === 'selesai' ? 'Tutup' : 'Batal'))));
}

// Tombol + modal impor biodata Dapodik (halaman Data Siswa, TU/ADMIN)
function __pmImporDapodik({ onSelesai }) {
  const [buka, setBuka] = ue.useState(false);
  const kirim = (rows, mode, nama) => __pmJson(`${K$}?action=profil_murid_dapodik_impor`, { rows, mode, nama_file: nama });
  return a.jsxs(ue.Fragment, { children: [
    a.jsxs('button', { type: 'button', onClick: () => setBuka(true), className: __pmK.btnIndigo, title: 'Impor biodata dari file Excel ekspor Peserta Didik Dapodik',
      children: [a.jsx(If, { size: 18 }), ' ', a.jsx('span', { children: 'Impor Biodata Dapodik' })] }),
    buka && a.jsx(__pmImpor, {
      judul: 'Impor Biodata Dapodik',
      petunjuk: 'Unduh daftar Peserta Didik dari aplikasi Dapodik (Excel), lalu pilih file itu. Murid dicocokkan lewat NISN (cadangan NIK). Data Dapodik menimpa biodata, tetapi nama dan kelas tidak diubah. Murid yang tidak ada di SIMANTAP dilewati. Kolom bank/rekening dan lintang/bujur tidak disimpan.',
      kirim,
      statusTulis: ['PERBARUI'],
      kolomTambahan: true,
      labelStatus: { PERBARUI: 'Diperbarui', TIDAK_DITEMUKAN: 'Tidak ditemukan', KONFLIK: 'Konflik', GALAT: 'Galat', TOTAL: 'Total' },
      onTutup: () => setBuka(false),
      onSelesai,
    }),
  ] });
}

// Daftar murid per kelas (BK/KS/ADMIN: pemilih kelas + pencarian; wali: kelasnya) + impor asesmen
function __pmDaftar({ user, halaman, onNavigate, asal }) {
  const K = __pmK;
  const h = __pmH;
  const [daftarKelas, setDaftarKelas] = ue.useState([]);
  const [kelas, setKelas] = ue.useState('');
  const [cari, setCari] = ue.useState('');
  const [kataKunci, setKataKunci] = ue.useState('');
  const [data, setData] = ue.useState(null);
  const [memuat, setMemuat] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const [sem, setSem] = ue.useState('');
  const [impor, setImpor] = ue.useState(false);
  const [pesan, setPesan] = ue.useState('');
  const urutan = ue.useRef(0);
  const pilihKelas = !!halaman;

  ue.useEffect(() => {
    if (!pilihKelas) return undefined;
    let batal = false;
    __pmJson(`${K$}?action=get_classes`).then((j) => {
      if (batal) return;
      const list = Array.isArray(j) ? j : [];
      setDaftarKelas(list);
      const norm = (v) => String(v == null ? '' : v).toLowerCase().replace(/[\s_.-]/g, '');
      const hr = user && user.class_homeroom;
      const cocok = hr ? list.find((c) => norm(c.id) === norm(hr) || norm(c.name) === norm(hr)) : null;
      setKelas(cocok ? cocok.id : (list[0] ? list[0].id : ''));
    }).catch((e) => setGalat(e.message));
    return () => { batal = true; };
  }, [pilihKelas]);

  const muat = ue.useCallback(async () => {
    const id = ++urutan.current;
    if (pilihKelas && !kelas && !kataKunci) return;
    setMemuat(true); setGalat('');
    try {
      const q = kataKunci ? `&q=${encodeURIComponent(kataKunci)}` : (pilihKelas ? `&class_id=${encodeURIComponent(kelas)}` : '');
      const j = await __pmJson(`${K$}?action=profil_murid_daftar${q}`);
      if (id === urutan.current) {
        setData(j);
        setSem((s) => s || (j.periode_aktif.semester === 'GENAP' ? '2' : '1'));
      }
    } catch (e) {
      if (id === urutan.current) { setData(null); setGalat(e.message); }
    } finally {
      if (id === urutan.current) setMemuat(false);
    }
  }, [kelas, kataKunci, pilihKelas]);
  ue.useEffect(() => { muat(); }, [muat]);

  const kelasAktif = data && data.kelas ? data.kelas.id : kelas;
  const unduhTemplate = async () => {
    setPesan('');
    try {
      const j = await __pmJson(`${K$}?action=profil_murid_asesmen_template&class_id=${encodeURIComponent(kelasAktif)}&semester=${sem}`);
      __pmUnduh([j.header, ...j.rows], j.nama_file, 'Asesmen');
    } catch (e) { setPesan(e.message); }
  };
  const kirimAsesmen = (rows, mode, nama) => __pmJson(`${K$}?action=profil_murid_asesmen_impor`,
    { class_id: kelasAktif, semester: sem, rows, mode, nama_file: nama });
  const asalHalaman = asal || 'bk-profil-murid';
  const murid = data && Array.isArray(data.murid) ? data.murid : [];
  const modeCari = !!kataKunci;

  const bar = h('div', { className: K.bar },
    h('div', { className: K.barKiri },
      pilihKelas && h('select', { className: K.select, value: kelas, disabled: modeCari, onChange: (e) => setKelas(e.target.value) },
        ...daftarKelas.map((c) => h('option', { key: c.id, value: c.id }, c.name || c.id))),
      pilihKelas && h('form', { className: K.barKiri, onSubmit: (e) => { e.preventDefault(); setKataKunci(cari.trim()); } },
        h('input', { className: K.input, placeholder: 'Cari nama/NISN (semua kelas)', value: cari, onChange: (e) => setCari(e.target.value) }),
        h('button', { type: 'submit', className: K.btnPutih }, a.jsx(Ui, { size: 14 }), 'Cari'),
        modeCari && h('button', { type: 'button', className: K.btnPutih, onClick: () => { setCari(''); setKataKunci(''); } }, 'Reset'))),
    !modeCari && kelasAktif && h('div', { className: K.barKiri },
      h('select', { className: K.select, value: sem, onChange: (e) => setSem(e.target.value), title: 'Periode asesmen (tahun ajaran aktif)' },
        h('option', { value: '1' }, 'Asesmen Smt Ganjil'), h('option', { value: '2' }, 'Asesmen Smt Genap')),
      h('button', { type: 'button', className: K.btnPutih, onClick: unduhTemplate }, a.jsx(Ti, { size: 14 }), 'Unduh Template Asesmen'),
      h('button', { type: 'button', className: K.btnHijau, onClick: () => setImpor(true) }, a.jsx(If, { size: 14 }), 'Impor Asesmen')));

  let isi;
  if (memuat && !data) isi = h('div', { className: K.spinnerWrap }, h('div', { className: K.spinner }), h('p', { className: K.spinnerTeks }, 'Memuat daftar murid…'));
  else if (galat) isi = h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat);
  else if (!murid.length) isi = h('div', { className: K.kosong }, modeCari ? 'Tidak ada murid yang cocok.' : 'Belum ada murid aktif di kelas ini.');
  else {
    isi = h('div', { className: K.card },
      h('div', { className: K.cardHead },
        h('p', { className: K.judulKecil }, modeCari ? `Hasil pencarian "${kataKunci}"` : `Murid ${data.kelas ? data.kelas.name : ''} — ${murid.length} murid`),
        h('p', { className: K.teksKecil }, `Asesmen ✓ = sudah ada untuk ${data.periode_aktif.academic_year} ${data.periode_aktif.semester === 'GENAP' ? 'Genap' : 'Ganjil'}`)),
      h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
        h('thead', { className: K.thead }, h('tr', null,
          h('th', { className: K.th }, 'Nama'), modeCari && h('th', { className: K.th }, 'Kelas'),
          h('th', { className: K.thTengah }, 'Biodata'), h('th', { className: K.thTengah }, 'Asesmen'),
          h('th', { className: K.thTengah }, 'Poin'), h('th', { className: K.thTengah }, '% Hadir'))),
        h('tbody', { className: K.tbody }, ...murid.map((m) => h('tr', { key: m.student_id, className: K.tr },
          h('td', { className: K.tdNama },
            h('button', { type: 'button', className: K.namaBtn, onClick: () => __pmBuka(m.student_id, asalHalaman, onNavigate) }, m.name),
            h('span', { className: K.nisn }, `NISN ${m.nisn || '-'}`),
            !m.aktif && h('span', { className: K.badgeKuning }, m.status || 'Nonaktif')),
          modeCari && h('td', { className: K.td }, m.class_name),
          h('td', { className: K.tdTengah }, h('span', { className: m.ada_biodata ? K.badgeHijau : K.badgeAbu }, m.ada_biodata ? '✓' : '–')),
          h('td', { className: K.tdTengah }, h('span', { className: m.ada_asesmen_aktif ? K.badgeHijau : K.badgeAbu }, m.ada_asesmen_aktif ? '✓' : '–')),
          h('td', { className: K.tdTengah }, h('span', { className: m.poin > 0 ? K.badgeMerah : K.badgeAbu }, m.poin)),
          h('td', { className: K.tdTengah }, m.persen_hadir === null ? '-' : `${__pmAngka(m.persen_hadir)}%`)))))));
  }

  const konten = h('div', { className: K.wrap },
    bar,
    pesan && h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), pesan),
    isi,
    impor && a.jsx(__pmImpor, {
      judul: `Impor Asesmen & Rencana Karir — ${data && data.kelas ? data.kelas.name : ''} (Smt ${sem === '2' ? 'Genap' : 'Ganjil'})`,
      petunjuk: 'Gunakan file dari tombol "Unduh Template Asesmen", isi hasil angket (skor R/I/A/S/E/C 0–100, gaya belajar, kesulitan belajar, mapel termudah/tersulit maks. 3, rencana karir), lalu pilih file itu. Baris dicocokkan lewat NISN. Data periode yang sama akan ditimpa; baris tanpa isi dilewati.',
      kirim: kirimAsesmen,
      statusTulis: ['BARU', 'TIMPA'],
      labelStatus: { BARU: 'Baru', TIMPA: 'Ditimpa', LEWATI: 'Dilewati', GALAT: 'Galat', TOTAL: 'Total' },
      onTutup: () => setImpor(false),
      onSelesai: () => muat(),
    }));

  if (!halaman) return konten;
  return h('div', { className: K.halaman },
    a.jsx(zi, {
      title: 'Profil Murid 360°',
      description: 'Biodata Dapodik, asesmen non-kognitif, rencana karir, nilai, kehadiran, dan perilaku murid dalam satu halaman.',
      category: 'Bimbingan & Konseling',
      color: 'blue',
      illustration: a.jsx(Tce, { size: 70, className: K.ilustrasi }),
    }),
    konten);
}

// Grafik garis tren nilai acuan per semester (SVG, tanpa pustaka)
function __pmTren({ tren, kkm }) {
  const K = __pmK;
  const h = __pmH;
  const WARNA = ['#059669', '#2563eb', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];
  const mapel = tren.mapel || [];
  const [pilih, setPilih] = ue.useState(() => mapel.slice(0, 8).map((m) => m.subject_id));
  const n = tren.periode.length;
  if (!n) return h('div', { className: K.kosongKecil }, 'Belum ada data nilai untuk grafik tren.');
  const W = 900, H = 260, L = 40, R = 24, T = 14, B = 40;
  const jangkar = (i) => (n === 1 ? 'middle' : i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle');
  const x = (i) => (n === 1 ? L + (W - L - R) / 2 : L + i * (W - L - R) / (n - 1));
  const y = (v) => T + (100 - Math.max(0, Math.min(100, v))) * (H - T - B) / 100;
  const seri = [
    ...mapel.filter((m) => pilih.includes(m.subject_id)).map((m, i) => ({ nama: m.subject_name, nilai: m.nilai, warna: WARNA[mapel.indexOf(m) % WARNA.length], tebal: 2 })),
    { nama: 'Rata-rata', nilai: tren.rata_rata, warna: '#0f172a', tebal: 3 },
  ];
  const garis = (nilai) => {
    let d = ''; let mulai = true;
    nilai.forEach((v, i) => {
      if (v === null || v === undefined) { mulai = true; return; }
      d += `${mulai ? 'M' : 'L'}${x(i).toFixed(1)},${y(v).toFixed(1)} `;
      mulai = false;
    });
    return d.trim();
  };
  const svg = h('svg', { viewBox: `0 0 ${W} ${H}`, width: '100%', role: 'img', 'aria-label': 'Grafik tren nilai per semester' },
    ...[0, 25, 50, 75, 100].map((g) => h('g', { key: 'g' + g },
      h('line', { x1: L, x2: W - R, y1: y(g), y2: y(g), stroke: '#e2e8f0', strokeWidth: 1 }),
      h('text', { x: L - 6, y: y(g) + 4, textAnchor: 'end', fontSize: 11, fill: '#94a3b8' }, String(g)))),
    h('line', { key: 'kkm', x1: L, x2: W - R, y1: y(kkm), y2: y(kkm), stroke: '#dc2626', strokeWidth: 1.5, strokeDasharray: '5 4' }),
    h('text', { key: 'kkmT', x: L + 4, y: y(kkm) - 4, textAnchor: 'start', fontSize: 11, fill: '#dc2626' }, `KKTP ${kkm}`),
    ...tren.periode.map((p, i) => h('text', { key: 'p' + i, x: x(i), y: H - B + 20, textAnchor: jangkar(i), fontSize: 11, fill: '#64748b' },
      p.replace(' GANJIL', ' Ganjil').replace(' GENAP', ' Genap'))),
    ...seri.map((s, si) => h('g', { key: 's' + si },
      h('path', { d: garis(s.nilai), fill: 'none', stroke: s.warna, strokeWidth: s.tebal }),
      ...s.nilai.map((v, i) => (v === null || v === undefined ? null
        : h('circle', { key: 'c' + i, cx: x(i), cy: y(v), r: s.tebal + 1.5, fill: s.warna },
          h('title', null, `${s.nama} — ${tren.periode[i]}: ${__pmAngka(v)}`)))))));
  return h('div', { className: K.wrap },
    svg,
    h('div', { className: K.legenda }, ...seri.map((s) => h('span', { key: s.nama, className: K.legendaItem },
      h('span', { className: K.titik, style: { backgroundColor: s.warna } }), s.nama))),
    mapel.length > 1 && h('div', { className: K.ringkas }, ...mapel.map((m) => h('button', {
      key: m.subject_id, type: 'button',
      className: pilih.includes(m.subject_id) ? K.badgeHijau : K.badgeAbu,
      onClick: () => setPilih((p) => (p.includes(m.subject_id) ? p.filter((v) => v !== m.subject_id) : [...p, m.subject_id])),
    }, m.subject_name))));
}

// Form edit asesmen + rencana karir (periode tahun ajaran aktif)
function __pmFormAsesmen({ data, onTutup, onTersimpan }) {
  const K = __pmK;
  const h = __pmH;
  const ta = data.periode_aktif.academic_year;
  const [sem, setSem] = ue.useState(data.periode_aktif.semester === 'GENAP' ? '2' : '1');
  const cari = (s) => data.asesmen.find((x) => x.academic_year === ta && x.semester === (s === '2' ? 'GENAP' : 'GANJIL')) || null;
  const awal = (s) => {
    const x = cari(s) || {};
    const f = (k) => (x[k] === null || x[k] === undefined ? '' : String(x[k]));
    return {
      riasec_r: f('riasec_r'), riasec_i: f('riasec_i'), riasec_a: f('riasec_a'), riasec_s: f('riasec_s'), riasec_e: f('riasec_e'), riasec_c: f('riasec_c'),
      riasec_kode: f('riasec_kode'), gaya_belajar: f('gaya_belajar'), skor_visual: f('skor_visual'), skor_auditori: f('skor_auditori'),
      skor_kinestetik: f('skor_kinestetik'), kesulitan_belajar: f('kesulitan_belajar'),
      mapel_termudah: (x.mapel_termudah || []).join(', '), mapel_tersulit: (x.mapel_tersulit || []).join(', '),
      cita_cita: f('cita_cita'), rencana_lulus: f('rencana_lulus'), jurusan_tujuan: f('jurusan_tujuan'),
      institusi_tujuan: f('institusi_tujuan'), catatan_karir: f('catatan_karir'),
    };
  };
  const [form, setForm] = ue.useState(() => awal(sem));
  const [sibuk, setSibuk] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const ubah = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const gantiSem = (s) => { setSem(s); setForm(awal(s)); setGalat(''); };
  const ada = !!cari(sem);

  const simpan = async (e) => {
    e.preventDefault();
    setSibuk(true); setGalat('');
    try {
      await __pmJson(`${K$}?action=profil_murid_asesmen_simpan`, { student_id: data.murid.student_id, semester: sem, ...form });
      onTersimpan();
    } catch (er) { setGalat(er.message); } finally { setSibuk(false); }
  };
  const hapus = async () => {
    if (!window.confirm(`Hapus data asesmen & rencana karir ${ta} semester ${sem === '2' ? 'Genap' : 'Ganjil'}?`)) return;
    setSibuk(true); setGalat('');
    try {
      await __pmJson(`${K$}?action=profil_murid_asesmen_hapus`, { student_id: data.murid.student_id, academic_year: ta, semester: sem });
      onTersimpan();
    } catch (er) { setGalat(er.message); } finally { setSibuk(false); }
  };
  const fld = (k, label, extra) => h('div', { key: k },
    h('label', { className: K.label }, label),
    h('input', { className: K.field, value: form[k], onChange: ubah(k), ...(extra || {}) }));
  const angka = { type: 'number', min: 0, max: 100, step: 'any' };

  return h('div', { className: K.overlay, onClick: (e) => { if (e.target === e.currentTarget && !sibuk) onTutup(); } },
    h('form', { className: K.modal, onSubmit: simpan },
      h('div', { className: K.modalHead },
        h('div', null, h('p', { className: K.subjudul }, 'Edit Asesmen & Rencana Karir'), h('p', { className: K.teksKecil }, `${data.murid.name} — Tahun ajaran ${ta}`)),
        h('button', { type: 'button', className: K.tutup, onClick: onTutup, 'aria-label': 'Tutup' }, a.jsx(pi, { size: 18 }))),
      h('div', { className: K.modalBody },
        galat && h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat),
        h('div', null, h('label', { className: K.label }, 'Semester'),
          h('select', { className: K.select, value: sem, onChange: (e) => gantiSem(e.target.value) },
            h('option', { value: '1' }, 'Ganjil'), h('option', { value: '2' }, 'Genap'))),
        h('p', { className: K.judulKecil }, 'Skor RIASEC (0–100). Kode dihitung otomatis dari 3 skor tertinggi.'),
        h('div', { className: K.grid3 }, ...['r', 'i', 'a', 's', 'e', 'c'].map((x) => fld('riasec_' + x, x.toUpperCase(), angka))),
        h('div', { className: K.grid2 },
          fld('riasec_kode', 'Kode RIASEC (bila tanpa skor)', { maxLength: 3, placeholder: 'mis. SIA' }),
          h('div', null, h('label', { className: K.label }, 'Gaya belajar'),
            h('select', { className: K.field, value: form.gaya_belajar, onChange: ubah('gaya_belajar') },
              h('option', { value: '' }, '— otomatis dari skor / kosong —'), h('option', { value: 'VISUAL' }, 'Visual'),
              h('option', { value: 'AUDITORI' }, 'Auditori'), h('option', { value: 'KINESTETIK' }, 'Kinestetik')))),
        h('div', { className: K.grid3 },
          fld('skor_visual', 'Skor Visual', angka), fld('skor_auditori', 'Skor Auditori', angka), fld('skor_kinestetik', 'Skor Kinestetik', angka)),
        h('div', null, h('label', { className: K.label }, 'Kesulitan belajar'),
          h('textarea', { className: K.field, rows: 3, maxLength: 1000, value: form.kesulitan_belajar, onChange: ubah('kesulitan_belajar') })),
        h('div', { className: K.grid2 },
          fld('mapel_termudah', 'Mapel termudah (maks. 3, pisahkan koma)'),
          fld('mapel_tersulit', 'Mapel tersulit (maks. 3, pisahkan koma)')),
        h('p', { className: K.judulKecil }, 'Rencana karir'),
        h('div', { className: K.grid2 },
          fld('cita_cita', 'Cita-cita', { maxLength: 150 }),
          h('div', null, h('label', { className: K.label }, 'Rencana setelah lulus'),
            h('select', { className: K.field, value: form.rencana_lulus, onChange: ubah('rencana_lulus') },
              h('option', { value: '' }, '—'), h('option', { value: 'KULIAH' }, 'Kuliah'), h('option', { value: 'KERJA' }, 'Kerja'),
              h('option', { value: 'WIRAUSAHA' }, 'Wirausaha'), h('option', { value: 'KULIAH_KERJA' }, 'Kuliah sambil kerja'),
              h('option', { value: 'BELUM_TAHU' }, 'Belum tahu'), h('option', { value: 'LAINNYA' }, 'Lainnya'))),
          fld('jurusan_tujuan', 'Jurusan tujuan', { maxLength: 150 }),
          fld('institusi_tujuan', 'Institusi/tempat tujuan', { maxLength: 150 })),
        h('div', null, h('label', { className: K.label }, 'Catatan karir'),
          h('textarea', { className: K.field, rows: 3, maxLength: 1000, value: form.catatan_karir, onChange: ubah('catatan_karir') }))),
      h('div', { className: K.modalFoot },
        ada && h('button', { type: 'button', className: K.btnMerah, disabled: sibuk, onClick: hapus }, a.jsx(ar, { size: 14 }), 'Hapus periode ini'),
        h('button', { type: 'button', className: K.btnPutih, disabled: sibuk, onClick: onTutup }, 'Batal'),
        h('button', { type: 'submit', className: K.btnHijau, disabled: sibuk }, sibuk ? 'Menyimpan…' : 'Simpan'))));
}

// Halaman Profil Murid 360°
function __pmProfil({ user, onNavigate }) {
  const K = __pmK;
  const h = __pmH;
  const baca = (k, w) => { try { return window[w] || sessionStorage.getItem(k) || ''; } catch (e) { return window[w] || ''; } };
  const sid = baca('simantap_pm_sid', '__pmSid');
  const asal = baca('simantap_pm_asal', '__pmAsal');
  const [data, setData] = ue.useState(null);
  const [galat, setGalat] = ue.useState('');
  const [memuat, setMemuat] = ue.useState(true);
  const [periode, setPeriode] = ue.useState(0);
  const [tab, setTab] = ue.useState('catatan');
  const [edit, setEdit] = ue.useState(false);
  const [lainBuka, setLainBuka] = ue.useState(false);

  const muat = ue.useCallback(async () => {
    if (!sid) { setGalat('Murid belum dipilih. Buka profil dari daftar murid.'); setMemuat(false); return; }
    setMemuat(true); setGalat('');
    try { setData(await __pmJson(`${K$}?action=profil_murid_get&student_id=${encodeURIComponent(sid)}`)); setPeriode(0); }
    catch (e) { setGalat(e.message); setData(null); }
    finally { setMemuat(false); }
  }, [sid]);
  ue.useEffect(() => { muat(); }, [muat]);

  const kembali = () => onNavigate(asal || (user && user.is_bk ? 'bk-profil-murid' : (user && user.class_homeroom ? 'classroom-dashboard' : 'bk-profil-murid')));
  const tombolKembali = h('button', { type: 'button', className: K.btnPutih, onClick: kembali }, a.jsx(kp, { size: 14 }), 'Kembali');

  if (memuat) return h('div', { className: K.halaman }, h('div', { className: K.spinnerWrap }, h('div', { className: K.spinner }), h('p', { className: K.spinnerTeks }, 'Memuat profil murid…')));
  if (galat || !data) return h('div', { className: K.halaman }, h('div', { className: K.bar }, tombolKembali), h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat || 'Profil tidak dapat dimuat.'));

  const m = data.murid;
  const b = data.biodata;
  const ak = data.akademik;
  const kh = data.kehadiran;
  const rw = data.riwayat;
  const semLabel = (s) => (s === 'GENAP' ? 'Genap' : 'Ganjil');
  const kv = (label, nilai) => h('div', { key: label }, h('span', { className: K.kvLabel }, label), h('span', { className: K.kvNilai }, __pmIsi(nilai)));
  const kartu = (judul, kanan, ...isi) => h('div', { className: K.card },
    h('div', { className: K.cardHead }, h('p', { className: K.subjudul }, judul), kanan || null),
    h('div', { className: K.cardBody }, ...isi));
  const tabel = (kolom, barisData, kosong) => (barisData.length === 0 ? h('div', { className: K.kosongKecil }, kosong)
    : h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
      h('thead', { className: K.thead }, h('tr', null, ...kolom.map((c, i) => h('th', { key: i, className: c.tengah ? K.thTengah : K.th }, c.judul)))),
      h('tbody', { className: K.tbody }, ...barisData.map((r, ri) => h('tr', { key: ri, className: r.__merah ? K.trMerah : K.tr },
        ...kolom.map((c, i) => h('td', { key: i, className: c.tengah ? K.tdTengah : K.td }, c.isi(r)))))))));

  // --- 1. Biodata ---
  const ortu = (label, o) => (o && Object.keys(o).length ? h('div', { key: label, className: K.info },
    h('p', { className: K.judulKecil }, label),
    h('div', { className: K.kv }, kv('Nama', o.nama), kv('NIK', o.nik), kv('Tahun lahir', o.tahun_lahir), kv('Pendidikan', o.pendidikan), kv('Pekerjaan', o.pekerjaan), kv('Penghasilan', o.penghasilan))) : null);
  const bagianBiodata = kartu('1. Identifikasi Profil Murid', b ? h('span', { className: K.badgeBiru }, `Dapodik · ${__pmTanggal(b.diimpor_pada)}`) : h('span', { className: K.badgeAbu }, 'Dapodik belum diimpor'),
    !b && h('div', { className: K.peringatan }, 'Biodata Dapodik belum diimpor. Hubungi Tata Usaha (menu Data Siswa → Impor Biodata Dapodik).'),
    h('div', { className: K.kv },
      kv('NISN', m.nisn), kv('NIPD', b && b.nipd), kv('NIK', m.nik), kv('No. KK', b && b.no_kk),
      kv('Tempat, tanggal lahir', `${__pmIsi(m.tempat_lahir)}, ${__pmTanggal(m.tanggal_lahir)}`), kv('Jenis kelamin', m.jenis_kelamin), kv('Agama', m.agama),
      kv('Anak ke / saudara kandung', b ? `${__pmIsi(b.anak_ke)} / ${__pmIsi(b.jml_saudara)}` : null),
      kv('Kebutuhan khusus', b && b.kebutuhan_khusus),
      kv('Alamat', [m.alamat, b && (b.rt || b.rw) ? `RT ${__pmIsi(b.rt)}/RW ${__pmIsi(b.rw)}` : '', b && b.dusun, b && b.kelurahan, b && b.kecamatan, b && b.kode_pos].filter(Boolean).join(', ')),
      kv('Jenis tinggal / transportasi', b ? `${__pmIsi(b.jenis_tinggal)} / ${__pmIsi(b.alat_transportasi)}` : null),
      kv('Jarak ke sekolah', b && b.jarak_rumah_km !== null ? `${__pmAngka(b.jarak_rumah_km)} km` : null),
      kv('HP / Email', b ? `${__pmIsi(b.hp)} / ${__pmIsi(b.email)}` : null),
      kv('Berat / tinggi / lingkar kepala', b ? `${__pmIsi(b.berat_badan)} kg / ${__pmIsi(b.tinggi_badan)} cm / ${__pmIsi(b.lingkar_kepala)} cm` : null),
      kv('Bantuan', b ? `KIP: ${__pmIsi(b.penerima_kip)}${b.no_kip ? ' (' + b.no_kip + ')' : ''} · KPS/PKH: ${__pmIsi(b.penerima_kps)} · KKS: ${__pmIsi(b.no_kks)}` : null),
      kv('Layak PIP', b ? `${__pmIsi(b.layak_pip)}${b.alasan_layak_pip ? ' — ' + b.alasan_layak_pip : ''}` : null),
      kv('Sekolah asal', b && b.sekolah_asal),
      kv('Orang tua/wali (SIMANTAP)', `${__pmIsi(m.parent_name)} · ${__pmIsi(m.pekerjaan_ortu)} · ${__pmIsi(m.parent_phone)}`)),
    b && h('div', { className: K.grid2 }, ortu('Ayah', b.ayah), ortu('Ibu', b.ibu), ortu('Wali', b.wali)),
    b && Object.keys(b.dapodik_lain || {}).length > 0 && h('div', null,
      h('button', { type: 'button', className: K.btnPutih, onClick: () => setLainBuka((v) => !v) }, lainBuka ? 'Sembunyikan data Dapodik lainnya' : 'Data Dapodik lainnya'),
      lainBuka && h('div', { className: K.info }, h('div', { className: K.kv }, ...Object.entries(b.dapodik_lain).map(([k, v]) => kv(k, v))))),
    data.akses.disamarkan && h('p', { className: K.teksKecil }, 'NIK dan No. KK disamarkan untuk wali kelas.'));

  // --- 2 & 3. Asesmen + karir ---
  const asesmen = data.asesmen[periode] || null;
  const pilihPeriode = data.asesmen.length > 1 && h('select', { className: K.select, value: periode, onChange: (e) => setPeriode(Number(e.target.value)) },
    ...data.asesmen.map((x, i) => h('option', { key: x.id, value: i }, `${x.academic_year} ${semLabel(x.semester)}`)));
  const kananAsesmen = h('div', { className: K.barKiri },
    pilihPeriode,
    asesmen && !pilihPeriode && h('span', { className: K.badgeAbu }, `${asesmen.academic_year} ${semLabel(asesmen.semester)}`),
    data.akses.bisa_edit_asesmen && h('button', { type: 'button', className: K.btnPutih, onClick: () => setEdit(true) }, a.jsx(fue, { size: 14 }), asesmen ? 'Edit' : 'Isi asesmen'));
  const TIPE = { R: 'Realistic', I: 'Investigative', A: 'Artistic', S: 'Social', E: 'Enterprising', C: 'Conventional' };
  const GAYA = { VISUAL: 'Visual', AUDITORI: 'Auditori', KINESTETIK: 'Kinestetik' };
  const RENCANA = { KULIAH: 'Kuliah', KERJA: 'Kerja', WIRAUSAHA: 'Wirausaha', KULIAH_KERJA: 'Kuliah sambil kerja', BELUM_TAHU: 'Belum tahu', LAINNYA: 'Lainnya' };
  const batang = (label, v, biru) => h('div', { key: label, className: K.barBaris },
    h('span', { className: K.barLabel }, label),
    h('div', { className: K.barTrack }, h('div', { className: biru ? K.barIsiBiru : K.barIsi, style: { width: `${Math.max(0, Math.min(100, Number(v) || 0))}%` } })),
    h('span', { className: K.barAngka }, __pmAngka(v)));
  const adaSkorRiasec = asesmen && ['r', 'i', 'a', 's', 'e', 'c'].some((x) => asesmen['riasec_' + x] !== null);
  const adaSkorVak = asesmen && ['skor_visual', 'skor_auditori', 'skor_kinestetik'].some((x) => asesmen[x] !== null);
  const chips = (list) => (list && list.length ? h('div', null, ...list.map((x) => h('span', { key: x, className: K.chip }, x))) : h('span', { className: K.kvNilai }, '-'));
  const bagianAsesmen = kartu('2. Asesmen Non-Kognitif', kananAsesmen,
    !asesmen ? h('div', { className: K.kosongKecil }, 'Belum ada asesmen.') : h('div', { className: K.grid2 },
      h('div', { className: K.wrap },
        h('div', null, h('span', { className: K.kvLabel }, 'Kode RIASEC'),
          h('p', { className: K.kode }, __pmIsi(asesmen.riasec_kode)),
          h('p', { className: K.teksKecil }, (asesmen.riasec_kode || '').split('').map((c) => TIPE[c]).filter(Boolean).join(' · '))),
        adaSkorRiasec && h('div', { className: K.wrap }, ...['R', 'I', 'A', 'S', 'E', 'C'].map((c) => batang(`${c} ${TIPE[c]}`, asesmen['riasec_' + c.toLowerCase()])))),
      h('div', { className: K.wrap },
        h('div', null, h('span', { className: K.kvLabel }, 'Gaya belajar'), h('span', { className: K.kvNilai }, GAYA[asesmen.gaya_belajar] || '-')),
        adaSkorVak && h('div', { className: K.wrap }, batang('Visual', asesmen.skor_visual, true), batang('Auditori', asesmen.skor_auditori, true), batang('Kinestetik', asesmen.skor_kinestetik, true)),
        kv('Kesulitan belajar', asesmen.kesulitan_belajar),
        h('div', null, h('span', { className: K.kvLabel }, 'Mapel termudah'), chips(asesmen.mapel_termudah)),
        h('div', null, h('span', { className: K.kvLabel }, 'Mapel tersulit'), chips(asesmen.mapel_tersulit)),
        h('p', { className: K.teksKecil }, `Sumber: ${asesmen.sumber === 'IMPOR' ? 'impor Excel' : 'input manual'} · diubah ${asesmen.diubah_oleh_nama || '-'} · ${__pmTanggal(asesmen.updated_at)}`))));
  const bagianKarir = kartu('3. Rencana Karir', asesmen ? h('span', { className: K.badgeAbu }, `${asesmen.academic_year} ${semLabel(asesmen.semester)}`) : null,
    !asesmen ? h('div', { className: K.kosongKecil }, 'Belum ada rencana karir.') : h('div', { className: K.kv },
      kv('Cita-cita', asesmen.cita_cita), kv('Rencana setelah lulus', RENCANA[asesmen.rencana_lulus]),
      kv('Jurusan tujuan', asesmen.jurusan_tujuan), kv('Institusi/tempat tujuan', asesmen.institusi_tujuan),
      kv('Catatan', asesmen.catatan_karir)));

  // --- 4. Akademik ---
  const nilaiAda = ak.semester_aktif.filter((s) => s.ref_score !== null);
  const bawah = nilaiAda.filter((s) => s.below_kkm);
  const bagianAkademik = kartu(`4. Akademik — Semester ${semLabel(data.periode_aktif.semester)} ${data.periode_aktif.academic_year}`, h('span', { className: K.badgeAbu }, `KKTP ${ak.kkm}`),
    bawah.length > 0 && h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), `${bawah.length} mapel di bawah KKTP: ${bawah.map((s) => s.subject_name).join(', ')}`),
    tabel([
      { judul: 'Mata pelajaran', isi: (s) => h('div', null, h('span', { className: K.kvNilai }, s.subject_name), h('span', { className: K.nisn }, (s.teachers || []).join(', '))) },
      { judul: 'Nilai', tengah: true, isi: (s) => h('span', { className: s.ref_score === null ? K.badgeAbu : s.below_kkm ? K.badgeMerah : K.badgeHijau }, __pmAngka(s.ref_score)) },
      { judul: 'Status', tengah: true, isi: (s) => (s.ref_score === null ? 'Belum ada penilaian' : s.ref_is_provisional ? 'Sementara (harian)' : 'Nilai akhir') },
      { judul: 'Tertinggal T/K', tengah: true, isi: (s) => `${s.missing_tugas} / ${s.missing_kuis}` },
    ], ak.semester_aktif.map((s) => ({ ...s, __merah: s.below_kkm })), m.aktif ? 'Belum ada mapel/penilaian semester ini.' : 'Murid tidak aktif.'),
    h('p', { className: K.judulKecil }, 'Tren nilai per semester'),
    a.jsx(__pmTren, { tren: ak.tren, kkm: ak.kkm }),
    h('div', { className: K.slot }, 'Target akademik — tersedia pada fitur Bimbingan Guru Wali.'));

  // --- 5. Kompetensi ---
  const bagianKompetensi = kartu('5. Kompetensi', null,
    h('div', { className: K.slot }, 'Grafik kompetensi 8 dimensi profil lulusan — tersedia pada fitur Bimbingan Guru Wali.'));

  // --- 6. Kehadiran ---
  const hr = kh.harian;
  const totalHari = hr.hadir + hr.sakit + hr.izin + hr.alfa;
  const stat = (label, v, merah) => h('div', { key: label, className: K.stat }, h('p', { className: K.statLabel }, label), h('p', { className: merah ? K.statNilaiMerah : K.statNilai }, v));
  const NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  const bagianKehadiran = kartu(`6. Kehadiran — Semester ${semLabel(data.periode_aktif.semester)}`, null,
    h('div', { className: K.grid4 },
      stat('Hadir', hr.persen_hadir === null ? '-' : `${__pmAngka(hr.persen_hadir)}%`, hr.persen_hadir !== null && hr.persen_hadir < 90),
      stat('Sakit', hr.sakit), stat('Izin', hr.izin), stat('Alfa', hr.alfa, hr.alfa > 0)),
    totalHari > 0 && h('div', { className: K.wrap }, ...hr.per_bulan.map((pb) => {
      const t = pb.hadir + pb.sakit + pb.izin + pb.alfa;
      const [y, mo] = pb.bulan.split('-');
      return batang(`${NAMA_BULAN[Number(mo) - 1]} ${y}`, t ? (pb.hadir / t) * 100 : 0);
    })),
    h('p', { className: K.judulKecil }, 'Presensi per mapel (jurnal)'),
    tabel([
      { judul: 'Mata pelajaran', isi: (r) => r.subject_name },
      { judul: 'Pertemuan', tengah: true, isi: (r) => r.pertemuan },
      { judul: 'H', tengah: true, isi: (r) => r.hadir }, { judul: 'S', tengah: true, isi: (r) => r.sakit },
      { judul: 'I', tengah: true, isi: (r) => r.izin }, { judul: 'A', tengah: true, isi: (r) => r.alfa },
    ], kh.per_mapel, 'Belum ada presensi per mapel dari jurnal.'),
    h('p', { className: K.judulKecil }, 'Tidak hadir terakhir'),
    tabel([
      { judul: 'Tanggal', isi: (r) => __pmTanggal(r.tanggal) },
      { judul: 'Status', isi: (r) => h('span', { className: r.status === 'ALFA' ? K.badgeMerah : K.badgeKuning }, r.status) },
      { judul: 'Sumber', isi: (r) => r.sumber },
    ], kh.tidak_hadir_terakhir, 'Tidak ada catatan tidak hadir.'));

  // --- 7. Riwayat ---
  const JENIS = { VIOLATION: 'Pelanggaran', LATE: 'Terlambat', OUT: 'Izin keluar', PARENT_CALL: 'Panggilan ortu' };
  const TABS = [
    { id: 'catatan', label: `Catatan Guru (${rw.catatan_guru.length})` },
    { id: 'pelanggaran', label: `Pelanggaran (${rw.pelanggaran.daftar.length})` },
    { id: 'panggilan', label: `Panggilan Ortu (${rw.panggilan_ortu.length})` },
    { id: 'dispensasi', label: `Dispensasi (${rw.dispensasi.length})` },
    { id: 'ekskul', label: `Ekskul (${rw.ekskul.length})` },
  ];
  let isiTab;
  if (tab === 'catatan') isiTab = tabel([
    { judul: 'Tanggal', isi: (r) => __pmTanggal(r.tanggal) }, { judul: 'Mapel', isi: (r) => r.subject_name },
    { judul: 'Guru', isi: (r) => r.teacher_name }, { judul: 'Catatan', isi: (r) => r.catatan },
  ], rw.catatan_guru, 'Belum ada catatan guru di jurnal tahun ajaran ini.');
  else if (tab === 'pelanggaran') isiTab = h('div', { className: K.wrap },
    h('div', { className: K.ringkas }, h('span', { className: K.chip }, `Total poin: ${m.total_poin}`),
      ...rw.pelanggaran.rekap.map((r) => h('span', { key: r.type, className: K.chip }, `${JENIS[r.type] || r.type}: ${r.jumlah}× (${r.poin} poin)`))),
    tabel([
      { judul: 'Tanggal', isi: (r) => __pmTanggal(r.date) }, { judul: 'Jenis', isi: (r) => JENIS[r.type] || r.type },
      { judul: 'Keterangan', isi: (r) => r.details }, { judul: 'Poin', tengah: true, isi: (r) => r.points }, { judul: 'Status', isi: (r) => r.status },
    ], rw.pelanggaran.daftar, 'Tidak ada insiden tahun ajaran ini.'));
  else if (tab === 'panggilan') isiTab = tabel([
    { judul: 'Jadwal', isi: (r) => __pmTanggal(r.scheduled_date || r.created_at) }, { judul: 'Pemicu', isi: (r) => (r.trigger_type === 'AUTO' ? 'Otomatis' : 'Manual') },
    { judul: 'Poin', tengah: true, isi: (r) => r.total_points }, { judul: 'Status', isi: (r) => r.status }, { judul: 'Catatan', isi: (r) => __pmIsi(r.notes) },
  ], rw.panggilan_ortu, 'Belum pernah ada panggilan orang tua.');
  else if (tab === 'dispensasi') isiTab = tabel([
    { judul: 'Tanggal', isi: (r) => (r.tanggal_mulai === r.tanggal_selesai ? __pmTanggal(r.tanggal_mulai) : `${__pmTanggal(r.tanggal_mulai)} – ${__pmTanggal(r.tanggal_selesai)}`) },
    { judul: 'Kegiatan', isi: (r) => r.kegiatan }, { judul: 'No. surat', isi: (r) => __pmIsi(r.nomor_surat) },
  ], rw.dispensasi, 'Tidak ada dispensasi tahun ajaran ini.');
  else isiTab = tabel([
    { judul: 'Ekstrakurikuler', isi: (r) => r.name }, { judul: 'Status', isi: (r) => h('span', { className: r.status === 'AKTIF' ? K.badgeHijau : K.badgeAbu }, r.status) },
    { judul: 'Bergabung', isi: (r) => __pmTanggal(r.joined_at) },
  ], rw.ekskul, 'Belum mengikuti ekstrakurikuler.');
  const bagianRiwayat = kartu('7. Riwayat & Perilaku', null,
    h('div', { className: K.tabWrap }, ...TABS.map((t) => h('button', { key: t.id, type: 'button', className: tab === t.id ? K.tabOn : K.tabOff, onClick: () => setTab(t.id) }, t.label))),
    isiTab);

  // --- Kepala halaman ---
  const kepala = h('div', { className: K.kepala },
    a.jsx(Ci, { src: m.pas_foto, name: m.name, className: K.foto }),
    h('div', { className: K.flex1 },
      h('p', { className: K.judulKecil }, 'Profil Murid 360°'),
      h('p', { className: K.nama }, m.name),
      h('p', { className: K.teks }, `NISN ${__pmIsi(m.nisn)} · ${m.class_name} · TA ${data.periode_aktif.academic_year} ${semLabel(data.periode_aktif.semester)}`),
      h('div', { className: K.ringkas },
        h('span', { className: m.aktif ? K.badgeHijau : K.badgeKuning }, m.aktif ? 'Aktif' : (m.status || 'Nonaktif')),
        h('span', { className: m.total_poin > 0 ? K.badgeMerah : K.badgeAbu }, `${m.total_poin} poin pelanggaran`))),
    h('div', { className: K.kepalaKanan },
      tombolKembali,
      h('button', { type: 'button', className: K.btnPutih, onClick: muat }, a.jsx(po, { size: 14 }), 'Muat ulang'),
      h('button', { type: 'button', className: K.btnHijau, onClick: () => window.open(`${K$}?action=cetak_profil_murid&student_id=${encodeURIComponent(m.student_id)}`, '_blank') }, a.jsx(Ss, { size: 14 }), 'Cetak')));

  return h('div', { className: K.halaman },
    kepala, bagianBiodata, bagianAsesmen, bagianKarir, bagianAkademik, bagianKompetensi, bagianKehadiran, bagianRiwayat,
    edit && a.jsx(__pmFormAsesmen, { data, onTutup: () => setEdit(false), onTersimpan: () => { setEdit(false); muat(); } }));
}
/* eslint-enable no-undef */

const FUNGSI = [__pmH, __pmJson, __pmAngka, __pmTanggal, __pmIsi, __pmBuka, __pmBacaFile, __pmUnduh, __pmImpor,
  __pmImporDapodik, __pmDaftar, __pmTren, __pmFormAsesmen, __pmProfil];
const KOMPONEN_SRC = `const __pmK=${JSON.stringify(KELAS)};${FUNGSI.map((f) => f.toString()).join('')}`;

const ITEM_SIDEBAR_BK = 'a.jsx(Ox,{icon:a.jsx(D_,{size:20}),label:"Pantau Nilai Kelas",active:t==="bk-grades",onClick:()=>n("bk-grades"),collapsed:i})';
const patches = [
  {
    id: 'M0 komponen Profil Murid',
    from: 'function dot({user:e,onNavigate:t,activeSemester:n}){',
    to: `${KOMPONEN_SRC}function dot({user:e,onNavigate:t,activeSemester:n}){`,
  },
  {
    id: 'M1 per_siswa menyimpan student_id',
    from: 'Y.per_siswa.map(me=>({studentName:me.student_name,pas_foto:me.pas_foto,class:_rn,',
    to: 'Y.per_siswa.map(me=>({student_id:me.student_id,studentName:me.student_name,pas_foto:me.pas_foto,class:_rn,',
  },
  {
    id: 'M2 nama murid membuka profil',
    from: 'a.jsx("p",{className:"font-bold text-slate-700",children:z.studentName})]})}),a.jsx("td",{className:"px-6 py-4 text-center font-bold text-emerald-600",children:z.hadir})',
    to: 'a.jsx("button",{type:"button",title:"Buka Profil Murid 360°",onClick:()=>__pmBuka(z.student_id,"classroom-dashboard",t),className:"font-bold text-slate-700 hover:text-emerald-600 text-left",children:z.studentName})]})}),a.jsx("td",{className:"px-6 py-4 text-center font-bold text-emerald-600",children:z.hadir})',
  },
  {
    id: 'M3a tab Profil Murid',
    from: '{id:"grades",label:"📝 Nilai"}]',
    to: '{id:"grades",label:"📝 Nilai"},{id:"profil",label:"👤 Profil Murid"}]',
  },
  {
    id: 'M3b render tab Profil Murid',
    from: 'N==="grades"&&a.jsx(__pnkPantauNilaiKelas,{user:e,classId:r,semester:b}),',
    to: 'N==="grades"&&a.jsx(__pnkPantauNilaiKelas,{user:e,classId:r,semester:b}),N==="profil"&&a.jsx(__pmDaftar,{user:e,onNavigate:t,asal:"classroom-dashboard"}),',
  },
  {
    id: 'M4 menu sidebar BK',
    from: ITEM_SIDEBAR_BK,
    to: ITEM_SIDEBAR_BK + ',a.jsx(Ox,{icon:a.jsx(Tce,{size:20}),label:"Profil Murid 360°",active:t==="bk-profil-murid"||t==="profil-murid",onClick:()=>n("bk-profil-murid"),collapsed:i})',
  },
  {
    id: 'M5 menu ADMIN/KS di Manajemen Data',
    from: '{id:"portal-nilai",label:"Portal Nilai Ortu",icon:a.jsx(D_,{size:20}),roles:["ADMIN"]}]}],M=Number(',
    to: '{id:"portal-nilai",label:"Portal Nilai Ortu",icon:a.jsx(D_,{size:20}),roles:["ADMIN"]},{id:"bk-profil-murid",label:"Profil Murid 360°",icon:a.jsx(Tce,{size:20}),roles:["ADMIN","KEPALA_SEKOLAH"]}]}],M=Number(',
  },
  {
    id: 'M6 router halaman',
    from: 'e==="bk-grades"&&a.jsx(__pnkPantauNilaiKelas,{user:o,showClassPicker:!0,activeSemester:Ee}),',
    to: 'e==="bk-grades"&&a.jsx(__pnkPantauNilaiKelas,{user:o,showClassPicker:!0,activeSemester:Ee}),'
      + 'e==="bk-profil-murid"&&a.jsx(__pmDaftar,{user:o,halaman:!0,onNavigate:be=>t(be),asal:"bk-profil-murid"}),'
      + 'e==="profil-murid"&&a.jsx(__pmProfil,{user:o,onNavigate:be=>t(be)}),',
  },
  {
    id: 'M7 daftar halaman dikenal',
    from: '"bk-incidents","bk-grades","portal-nilai"',
    to: '"bk-incidents","bk-grades","bk-profil-murid","profil-murid","portal-nilai"',
  },
  {
    id: 'M8 tombol Impor Biodata Dapodik di Data Siswa',
    from: 'X&&a.jsxs("button",{onClick:()=>ce(),className:"flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-xl',
    to: 'X&&a.jsx(__pmImporDapodik,{onSelesai:()=>ie()}),X&&a.jsxs("button",{onClick:()=>ce(),className:"flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-xl',
  },
];

function main() {
  const srcPath = path.join(ROOT, 'assets', SRC_NAME);
  const outPath = path.join(ROOT, 'assets', OUT_NAME);
  const cssPath = path.join(ROOT, 'assets', CSS_NAME);
  const appHtmlPath = path.join(ROOT, 'app.html');

  const src = fs.readFileSync(srcPath, 'utf8');
  const hash = crypto.createHash('sha256').update(fs.readFileSync(srcPath)).digest('hex');
  if (hash !== SRC_SHA256) fail(`hash ${SRC_NAME} berbeda (${hash}). Bundle sudah berubah, cari ulang anchor secara manual.`);

  // Semua class Tailwind yang disisipkan harus sudah ada di CSS terkompilasi.
  const css = fs.readFileSync(cssPath, 'utf8');
  const cssEscape = (c) => c.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
  const kelas = new Set();
  Object.values(KELAS).forEach((v) => v.split(/\s+/).filter(Boolean).forEach((c) => kelas.add(c)));
  for (const p of patches) {
    for (const m of p.to.replace(p.from, '').matchAll(/className:"([^"]*)"/g)) m[1].split(/\s+/).filter(Boolean).forEach((c) => kelas.add(c));
  }
  const hilang = [...kelas].filter((c) => {
    const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
    return !re.test(css);
  });
  if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));
  console.log(`OK  ${kelas.size} class Tailwind tersedia di CSS bundle`);

  // Identifier yang disisipkan tidak boleh sudah ada; identifier bundle yang dipakai harus ada di scope modul
  for (const nama of ['__pmK', '__pmProfil', '__pmDaftar', '"bk-profil-murid"', '"profil-murid"']) {
    if (src.includes(nama)) fail(`${nama} sudah ada di ${SRC_NAME}.`);
  }
  const wajib = {
    Tce: /[,;\s]Tce=Nt\("id-card"/, If: /[,;\s]If=Nt\("upload"/, Ti: /[,;\s]Ti=Nt\("download"/, Ss: /[,;\s]Ss=Nt\("printer"/,
    kp: /[,;\s]kp=Nt\("arrow-left"/, Z1: /[,;\s]Z1=Nt\("triangle-alert"/, po: /[,;\s]po=Nt\("refresh-cw"/, fue: /[,;\s]fue=Nt\("pencil"/,
    ar: /[,;\s]ar=Nt\("trash-2"/, pi: /[,;\s]pi=Nt\("x"/, Ui: /[,;\s]Ui=Nt\("search"/,
    C_: /function C_\(/, QC: /function QC\(/, Jl: /[,;\s]Jl=\{encode_col:/,
    'Jl.aoa_to_sheet': /Jl=\{[^}]*aoa_to_sheet:/, 'Jl.sheet_to_json': /Jl=\{[^}]*sheet_to_json:/, 'Jl.book_new': /Jl=\{[^}]*book_new:/,
    'Jl.book_append_sheet': /Jl=\{[^}]*book_append_sheet:/,
  };
  for (const [nama, re] of Object.entries(wajib)) if (!re.test(src)) fail(`identifier bundle ${nama} tidak ditemukan.`);

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
    const ref = `./assets/${SRC_NAME}`;
    if (html.split(ref).length - 1 !== 1) fail(`app.html tidak mereferensikan ${ref} tepat 1 kali.`);
    fs.writeFileSync(appHtmlPath, html.replace(ref, `./assets/${OUT_NAME}`), 'utf8');
    console.log(`app.html diarahkan ke assets/${OUT_NAME}`);
  }
}

if (require.main === module) main();
