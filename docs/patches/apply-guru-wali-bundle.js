// Patch bundle frontend: Penugasan murid dampingan Guru Wali.
// Spesifikasi: docs/specs/penugasan-guru-wali.md (§5). Catatan patch: docs/patches/guru-wali.md
//  G0 Komponen (__gwKelola + tab, modal, __gwDampingan, __gwKartuProfil) + peta class (__gwK),
//     disisipkan tepat sebelum komponen Dashboard Kelas Saya (dot).
//  G1+G2 Sidebar: grup baru "Bimbingan Guru Wali" (sebelum "Umum & Ekskul") berisi
//     "Guru Wali" (guru-wali, ADMIN/KEPALA_SEKOLAH) dan "Murid Dampingan" (murid-dampingan, GURU).
//  G3+G4 Router halaman guru-wali dan murid-dampingan + daftar halaman yang dikenal.
//  G5 Profil Murid 360°: baris "Guru wali" di kepala halaman dan kartu "Guru Wali" (aktif + riwayat).
//     Tombol edit asesmen sudah mengikuti akses.bisa_edit_asesmen (tidak diubah).
//  G6 Dashboard Kelas Saya: kolom "Guru Wali" di tabel per siswa.
//  G7 Pengaturan (tab konfigurasi, di bawah "Hari Sekolah"): ambang murid dampingan per guru wali.
// Prasyarat: patch Profil Murid 360° (apply-profil-murid-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-guru-wali-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-profilmurid2.js';
const OUT_NAME = 'index-CGWL69P7-guruwali1.js';
const SRC_SHA256 = 'abe9baa433a1f197ef3ccb0a72ac61410ef137b71d004d9388dd21c87be79492';
const CSS_NAME = 'index-CC1PBzaO.css';

function fail(msg) {
  console.error('GAGAL: ' + msg);
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Class Tailwind tambahan (selain __pmK dari patch Profil Murid), divalidasi terhadap CSS bundle.
// ---------------------------------------------------------------------------
const KELAS = {
  cek: 'w-4 h-4 cursor-pointer',
  trPilih: 'bg-emerald-50 transition-colors',
  trKlik: 'hover:bg-slate-50/50 transition-colors cursor-pointer',
  ok: 'p-4 rounded-2xl bg-emerald-50 text-emerald-700 text-sm font-bold',
  modalKecil: 'bg-white rounded-3xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto',
  daftarGulir: 'max-h-96 overflow-y-auto space-y-1',
  link: 'text-xs font-black text-blue-600 underline',
  tdKecil: 'px-4 py-3 text-xs text-slate-500',
  tdNowrap: 'px-4 py-3 text-sm text-slate-600 whitespace-nowrap',
  tdGuru: 'px-6 py-4 text-sm text-slate-500 whitespace-nowrap',
  thGuru: 'px-6 py-4',
  baris: 'flex flex-wrap items-center gap-2',
  nonaktif: 'opacity-50 cursor-not-allowed',
  kuotaInput: 'w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold focus:ring-2 focus:ring-blue-500 outline-none',
  kuotaLabel: 'text-[10px] font-black text-slate-500 uppercase tracking-widest flex items-center gap-1.5',
  kuotaWrap: 'space-y-1.5',
  kuotaKet: 'text-[10px] font-bold text-slate-400',
};

// ---------------------------------------------------------------------------
// G0 Komponen. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas dari scope modul bundle:
//   a = jsx runtime, ue = React, K$ = URL api/index.php, zi = kartu judul halaman, Ci = avatar (src, name),
//   C_ = XLSX.read, Jl = XLSX.utils,
//   dari patch Profil Murid: __pmK (class), __pmH (helper jsx), __pmTanggal, __pmAngka, __pmBuka, __pmUnduh,
//   ikon lucide: t0 (user-check), E4 (user-plus), y4 (file-spreadsheet), fE (history), wue (rotate-ccw), wa (users),
//   Ti (download), If (upload), Z1 (triangle-alert), po (refresh-cw), pi (x), Ui (search).
// __gwK = peta class tambahan (KELAS di atas).
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */
const __gwAlasan = [
  ['MUTASI_GURU', 'Mutasi guru'], ['PENSIUN', 'Pensiun'], ['BEBAN_KERJA', 'Beban kerja'],
  ['PERMINTAAN_MURID_ORTU', 'Permintaan murid/orang tua'], ['KOREKSI_DATA', 'Koreksi data'],
  ['MURID_NONAKTIF', 'Murid tidak aktif'], ['LAINNYA', 'Lainnya'],
];

async function __gwKirim(action, body, query) {
  const opsi = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
  const res = await fetch(`${K$}?action=${action}${query || ''}`, opsi);
  let j = null;
  try { j = await res.json(); } catch (e) { j = null; }
  return { ok: res.ok, status: res.status, json: j || {} };
}

function __gwGalat(r) {
  const p = r.json && r.json.error ? r.json.error : `Permintaan gagal (HTTP ${r.status}).`;
  return p === 'Unauthorized' ? 'Sesi berakhir, silakan login ulang.' : p;
}

async function __gwAmbil(action, query) {
  const r = await __gwKirim(action, undefined, query);
  if (!r.ok) throw new Error(__gwGalat(r));
  return r.json;
}

function __gwHariIni() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

function __gwPesanSimpan(j) {
  const bagian = [];
  if (j.dibuat) bagian.push(`${j.dibuat} penugasan baru`);
  if (j.diganti) bagian.push(`${j.diganti} guru wali diganti`);
  if (j.sama && j.sama.length) bagian.push(`${j.sama.length} murid sudah didampingi guru yang sama`);
  const teks = bagian.length ? bagian.join(', ') + '.' : 'Tidak ada perubahan.';
  const pk = j.peringatan_kuota;
  const daftar = Array.isArray(pk) ? pk : (pk ? [pk] : []);
  if (!daftar.length) return { jenis: 'ok', teks };
  return { jenis: 'warn', teks: `${teks} Perhatian: ${daftar.map((x) => `${x.nama} kini mendampingi ${x.jumlah} murid (ambang ${x.kuota})`).join('; ')}.` };
}

function __gwStatus(s) {
  const K = __pmK;
  const peta = {
    AKTIF: [K.badgeHijau, 'Aktif'], SELESAI: [K.badgeAbu, 'Selesai'],
    SELESAI_MURID_NONAKTIF: [K.badgeKuning, 'Selesai (murid nonaktif)'], DIBATALKAN: [K.badgeMerah, 'Dibatalkan'],
  };
  const [c, t] = peta[s] || [K.badgeAbu, s];
  return __pmH('span', { className: c }, t);
}

function __gwPilihAlasan({ nilai, onUbah, tanpaNonaktif }) {
  const h = __pmH;
  return h('select', { className: __pmK.field, value: nilai, onChange: (e) => onUbah(e.target.value) },
    h('option', { value: '' }, '— pilih alasan —'),
    ...__gwAlasan.filter(([k]) => !(tanpaNonaktif && k === 'MURID_NONAKTIF')).map(([k, l]) => h('option', { key: k, value: k }, l)));
}

function __gwModal({ judul, onTutup, sibuk, galat, isi, kaki }) {
  const K = __pmK;
  const h = __pmH;
  return h('div', { className: K.overlay, onClick: (e) => { if (e.target === e.currentTarget && !sibuk) onTutup(); } },
    h('div', { className: __gwK.modalKecil },
      h('div', { className: K.modalHead },
        h('p', { className: K.subjudul }, judul),
        h('button', { type: 'button', className: K.tutup, onClick: onTutup, disabled: sibuk, 'aria-label': 'Tutup' }, a.jsx(pi, { size: 18 }))),
      h('div', { className: K.modalBody },
        galat && h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat),
        isi),
      h('div', { className: K.modalFoot }, kaki)));
}

// Baca sheet pertama sebagai TEKS (CSV tidak diubah jadi angka; sel Excel diambil sesuai tampilannya),
// agar NIP 18 digit dan NISN bernol depan tidak kehilangan presisi seperti pada __pmBacaFile (raw).
async function __gwBacaFile(file) {
  const buf = await file.arrayBuffer();
  const wb = C_(new Uint8Array(buf), { type: 'array', raw: true });
  const ws = wb.Sheets[wb.SheetNames[0]];
  if (!ws) throw new Error('File tidak berisi sheet.');
  const rows = Jl.sheet_to_json(ws, { header: 1, raw: false, defval: '' }).map((r) => r.map((v) => (v === null || v === undefined ? '' : String(v))));
  while (rows.length && rows[rows.length - 1].every((v) => v.trim() === '')) rows.pop();
  if (!rows.length) throw new Error('File kosong.');
  return rows;
}

// Modal tugaskan / pindahkan: pilih guru + tanggal; 409 -> konfirmasi penggantian dengan alasan
function __gwModalTugaskan({ murid, guruList, kuota, kecuali, judul, onTutup, onSelesai }) {
  const K = __pmK;
  const h = __pmH;
  const [guru, setGuru] = ue.useState('');
  const [tanggal, setTanggal] = ue.useState(__gwHariIni());
  const [konfirmasi, setKonfirmasi] = ue.useState(null);
  const [alasan, setAlasan] = ue.useState(kecuali ? 'MUTASI_GURU' : '');
  const [catatan, setCatatan] = ue.useState('');
  const [sibuk, setSibuk] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const pilihan = guruList.filter((g) => g.uid !== kecuali);

  const kirim = async () => {
    if (!guru) { setGalat('Pilih guru wali terlebih dahulu.'); return; }
    setSibuk(true); setGalat('');
    try {
      const body = { guru_uid: guru, student_ids: murid.map((m) => m.id), tanggal_mulai: tanggal };
      if (konfirmasi) Object.assign(body, { ganti: true, alasan, catatan });
      const r = await __gwKirim('guru_wali_tugaskan', body);
      if (r.status === 409 && Array.isArray(r.json.perlu_konfirmasi)) { setKonfirmasi(r.json.perlu_konfirmasi); return; }
      if (!r.ok) { setGalat(__gwGalat(r)); return; }
      onSelesai(r.json);
    } catch (e) { setGalat(e.message); } finally { setSibuk(false); }
  };

  const isi = h('div', { className: K.wrap },
    h('p', { className: K.teks }, `${murid.length} murid dipilih: ${murid.slice(0, 6).map((m) => m.name).join(', ')}${murid.length > 6 ? `, dan ${murid.length - 6} lainnya` : ''}.`),
    h('div', { className: K.grid2 },
      h('div', null, h('label', { className: K.label }, 'Guru wali'),
        h('select', { className: K.field, value: guru, disabled: !!konfirmasi, onChange: (e) => setGuru(e.target.value) },
          h('option', { value: '' }, '— pilih guru —'),
          ...pilihan.map((g) => h('option', { key: g.uid, value: g.uid },
            `${g.nama} — ${g.jumlah_dampingan}/${kuota} murid${g.melebihi_kuota ? ' ⚠' : ''}`)))),
      h('div', null, h('label', { className: K.label }, konfirmasi ? 'Tanggal efektif' : 'Tanggal mulai'),
        h('input', { type: 'date', className: K.field, value: tanggal, max: __gwHariIni(), onChange: (e) => setTanggal(e.target.value) }))),
    konfirmasi && h('div', { className: K.peringatan },
      h('p', null, `${konfirmasi.length} murid sudah memiliki guru wali lain. Guru wali lama diakhiri sehari sebelum tanggal efektif:`),
      h('div', { className: __gwK.daftarGulir }, ...konfirmasi.map((k) => h('p', { key: k.student_id, className: K.daftarPesan },
        `• ${k.name}${k.class_name ? ` (${k.class_name})` : ''} — ${k.guru_lama_nama}, sejak ${__pmTanggal(k.tanggal_mulai_lama)}`)))),
    konfirmasi && h('div', { className: K.grid2 },
      h('div', null, h('label', { className: K.label }, 'Alasan penggantian'), a.jsx(__gwPilihAlasan, { nilai: alasan, onUbah: setAlasan, tanpaNonaktif: true })),
      h('div', null, h('label', { className: K.label }, alasan === 'LAINNYA' ? 'Catatan (wajib)' : 'Catatan (opsional)'),
        h('input', { className: K.field, maxLength: 500, value: catatan, onChange: (e) => setCatatan(e.target.value) }))));

  return a.jsx(__gwModal, {
    judul, onTutup, sibuk, galat, isi,
    kaki: [
      h('button', { key: 'b', type: 'button', className: K.btnPutih, disabled: sibuk, onClick: onTutup }, 'Batal'),
      h('button', { key: 's', type: 'button', className: K.btnHijau, disabled: sibuk || (konfirmasi && !alasan), onClick: kirim },
        sibuk ? 'Menyimpan…' : (konfirmasi ? 'Ganti guru wali' : 'Tugaskan')),
    ],
  });
}

// Modal akhiri penugasan tanpa pengganti
function __gwModalAkhiri({ penugasan, onTutup, onSelesai }) {
  const K = __pmK;
  const h = __pmH;
  const [tanggal, setTanggal] = ue.useState(__gwHariIni());
  const [alasan, setAlasan] = ue.useState('');
  const [catatan, setCatatan] = ue.useState('');
  const [sibuk, setSibuk] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const kirim = async () => {
    setSibuk(true); setGalat('');
    try {
      const r = await __gwKirim('guru_wali_akhiri', { penugasan_ids: penugasan.map((p) => p.id), tanggal_selesai: tanggal, alasan, catatan });
      if (!r.ok) { setGalat(__gwGalat(r)); return; }
      onSelesai(r.json);
    } catch (e) { setGalat(e.message); } finally { setSibuk(false); }
  };
  const isi = h('div', { className: K.wrap },
    h('p', { className: K.teks }, `Penugasan ${penugasan.length} murid akan diakhiri tanpa pengganti. Murid akan masuk daftar "Belum punya guru wali".`),
    h('div', { className: K.grid2 },
      h('div', null, h('label', { className: K.label }, 'Tanggal selesai'),
        h('input', { type: 'date', className: K.field, value: tanggal, max: __gwHariIni(), onChange: (e) => setTanggal(e.target.value) })),
      h('div', null, h('label', { className: K.label }, 'Alasan'), a.jsx(__gwPilihAlasan, { nilai: alasan, onUbah: setAlasan }))),
    h('div', null, h('label', { className: K.label }, alasan === 'LAINNYA' ? 'Catatan (wajib)' : 'Catatan (opsional)'),
      h('input', { className: K.field, maxLength: 500, value: catatan, onChange: (e) => setCatatan(e.target.value) })));
  return a.jsx(__gwModal, {
    judul: 'Akhiri penugasan guru wali', onTutup, sibuk, galat, isi,
    kaki: [
      h('button', { key: 'b', type: 'button', className: K.btnPutih, disabled: sibuk, onClick: onTutup }, 'Batal'),
      h('button', { key: 's', type: 'button', className: K.btnMerah, disabled: sibuk || !alasan, onClick: kirim }, sibuk ? 'Menyimpan…' : 'Akhiri penugasan'),
    ],
  });
}

// Modal batalkan penugasan aktif (salah input)
function __gwModalBatal({ baris, onTutup, onSelesai }) {
  const K = __pmK;
  const h = __pmH;
  const [alasan, setAlasan] = ue.useState('');
  const [sibuk, setSibuk] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const kirim = async () => {
    setSibuk(true); setGalat('');
    try {
      const r = await __gwKirim('guru_wali_batalkan', { penugasan_id: baris.id, alasan_batal: alasan });
      if (!r.ok) { setGalat(__gwGalat(r)); return; }
      onSelesai(r.json);
    } catch (e) { setGalat(e.message); } finally { setSibuk(false); }
  };
  const isi = h('div', { className: K.wrap },
    h('p', { className: K.teks }, `Penugasan ${baris.nama_murid} ke ${baris.guru_nama} (mulai ${__pmTanggal(baris.tanggal_mulai)}) akan ditandai DIBATALKAN dan tidak tampil di riwayat resmi. Gunakan ini hanya untuk salah input. Jika penugasan ini menggantikan guru wali sebelumnya, guru wali sebelumnya dipulihkan.`),
    h('div', null, h('label', { className: K.label }, 'Alasan pembatalan (wajib)'),
      h('textarea', { className: K.field, rows: 3, maxLength: 500, value: alasan, onChange: (e) => setAlasan(e.target.value) })));
  return a.jsx(__gwModal, {
    judul: 'Batalkan penugasan', onTutup, sibuk, galat, isi,
    kaki: [
      h('button', { key: 'b', type: 'button', className: K.btnPutih, disabled: sibuk, onClick: onTutup }, 'Tutup'),
      h('button', { key: 's', type: 'button', className: K.btnMerah, disabled: sibuk || !alasan.trim(), onClick: kirim }, sibuk ? 'Memproses…' : 'Batalkan penugasan'),
    ],
  });
}

// Tab Per Guru: daftar guru + panel dampingan guru terpilih
function __gwTabGuru({ ring, versi, onUbah, onPesan, onRiwayat }) {
  const K = __pmK;
  const G = __gwK;
  const h = __pmH;
  const [cari, setCari] = ue.useState('');
  const [pilih, setPilih] = ue.useState('');
  const [murid, setMurid] = ue.useState([]);
  const [memuat, setMemuat] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const [centang, setCentang] = ue.useState([]);
  const [modal, setModal] = ue.useState(null);

  ue.useEffect(() => {
    if (!pilih) { setMurid([]); return undefined; }
    let batal = false;
    setMemuat(true); setGalat(''); setCentang([]);
    __gwAmbil('guru_wali_murid', `&guru_uid=${encodeURIComponent(pilih)}`)
      .then((j) => { if (!batal) setMurid(j.murid || []); })
      .catch((e) => { if (!batal) setGalat(e.message); })
      .finally(() => { if (!batal) setMemuat(false); });
    return () => { batal = true; };
  }, [pilih, versi]);

  const guru = (ring.guru || []).filter((g) => !cari || `${g.nama} ${g.nip || ''}`.toLowerCase().includes(cari.toLowerCase()));
  const dipilih = (ring.guru || []).find((g) => g.uid === pilih);
  const semua = murid.length > 0 && centang.length === murid.length;
  const tog = (id) => setCentang((c) => (c.includes(id) ? c.filter((x) => x !== id) : [...c, id]));
  const muridTerpilih = murid.filter((m) => centang.includes(m.id));
  const selesai = (j, akhir) => { setModal(null); onPesan(akhir ? { jenis: 'ok', teks: `${j.diakhiri} penugasan diakhiri.` } : __gwPesanSimpan(j)); onUbah(); };

  const tabelGuru = h('div', { className: K.card },
    h('div', { className: K.cardHead },
      h('p', { className: K.judulKecil }, `${(ring.guru || []).length} guru · ambang ${ring.kuota} murid per guru`),
      h('input', { className: K.input, placeholder: 'Cari nama/NIP guru', value: cari, onChange: (e) => setCari(e.target.value) })),
    h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
      h('thead', { className: K.thead }, h('tr', null,
        h('th', { className: K.th }, 'Guru'), h('th', { className: K.th }, 'Peran lain'), h('th', { className: K.thTengah }, 'Murid dampingan'))),
      h('tbody', { className: K.tbody }, ...guru.map((g) => h('tr', { key: g.uid, className: g.uid === pilih ? G.trPilih : G.trKlik, onClick: () => setPilih(g.uid === pilih ? '' : g.uid) },
        h('td', { className: K.tdNama }, h('span', { className: K.namaBtn }, g.nama), h('span', { className: K.nisn }, g.nip ? `NIP ${g.nip}` : (g.email || ''))),
        h('td', { className: K.td }, h('div', { className: G.baris },
          g.class_homeroom && h('span', { className: K.badgeBiru }, `Wali kelas ${g.class_homeroom}`),
          g.is_bk && h('span', { className: K.badgeBiru }, 'Guru BK'))),
        h('td', { className: K.tdTengah }, h('span', { className: g.melebihi_kuota ? K.badgeKuning : (g.jumlah_dampingan ? K.badgeHijau : K.badgeAbu) },
          g.jumlah_dampingan, g.melebihi_kuota ? ' · melebihi ambang' : ''))))))),
    !guru.length && h('div', { className: K.kosongKecil }, 'Tidak ada guru yang cocok.'));

  let panel = null;
  if (dipilih) {
    let isi;
    if (memuat) isi = h('div', { className: K.kosongKecil }, 'Memuat…');
    else if (galat) isi = h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat);
    else if (!murid.length) isi = h('div', { className: K.kosongKecil }, 'Guru ini belum memiliki murid dampingan.');
    else isi = h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
      h('thead', { className: K.thead }, h('tr', null,
        h('th', { className: K.th }, h('input', { type: 'checkbox', className: G.cek, checked: semua, onChange: () => setCentang(semua ? [] : murid.map((m) => m.id)), 'aria-label': 'Pilih semua' })),
        h('th', { className: K.th }, 'Murid'), h('th', { className: K.th }, 'Kelas'), h('th', { className: K.th }, 'Sejak'))),
      h('tbody', { className: K.tbody }, ...murid.map((m) => h('tr', { key: m.id, className: centang.includes(m.id) ? G.trPilih : K.tr },
        h('td', { className: K.td }, h('input', { type: 'checkbox', className: G.cek, checked: centang.includes(m.id), onChange: () => tog(m.id) })),
        h('td', { className: K.tdNama }, h('span', { className: K.namaBtn }, m.name), h('span', { className: K.nisn }, `NISN ${m.nisn || '-'}`)),
        h('td', { className: K.td }, m.class_name),
        h('td', { className: G.tdNowrap }, m.penugasan ? __pmTanggal(m.penugasan.tanggal_mulai) : '-'))))));
    panel = h('div', { className: K.card },
      h('div', { className: K.cardHead },
        h('div', null, h('p', { className: K.subjudul }, `Murid dampingan ${dipilih.nama}`),
          h('p', { className: K.teksKecil }, `${murid.length} murid aktif${dipilih.melebihi_kuota ? ` — melebihi ambang ${ring.kuota}` : ''}`)),
        h('div', { className: K.barKiri },
          h('button', { type: 'button', className: K.btnPutih, onClick: () => onRiwayat({ uid: dipilih.uid, nama: dipilih.nama }) }, a.jsx(fE, { size: 14 }), 'Riwayat'),
          h('button', { type: 'button', className: K.btnPutih, disabled: !centang.length, onClick: () => setModal({ jenis: 'pindah', murid: muridTerpilih }) }, a.jsx(E4, { size: 14 }), `Pindahkan (${centang.length})`),
          h('button', { type: 'button', className: K.btnMerah, disabled: !centang.length, onClick: () => setModal({ jenis: 'akhiri', murid: muridTerpilih }) }, `Akhiri (${centang.length})`))),
      h('div', { className: K.cardBody }, isi));
  }

  return h('div', { className: K.wrap },
    tabelGuru, panel,
    modal && modal.jenis === 'pindah' && a.jsx(__gwModalTugaskan, {
      murid: modal.murid, guruList: ring.guru || [], kuota: ring.kuota, kecuali: pilih,
      judul: `Pindahkan dampingan ${dipilih ? dipilih.nama : ''}`, onTutup: () => setModal(null), onSelesai: (j) => selesai(j, false),
    }),
    modal && modal.jenis === 'akhiri' && a.jsx(__gwModalAkhiri, {
      penugasan: modal.murid.filter((m) => m.penugasan).map((m) => ({ id: m.penugasan.id, name: m.name })),
      onTutup: () => setModal(null), onSelesai: (j) => selesai(j, true),
    }));
}

// Tab Murid: saring, centang, tugaskan
function __gwTabMurid({ ring, kelasList, versi, filterAwal, onUbah, onPesan }) {
  const K = __pmK;
  const G = __gwK;
  const h = __pmH;
  const [kelas, setKelas] = ue.useState('');
  const [status, setStatus] = ue.useState(filterAwal || 'SEMUA');
  const [guru, setGuru] = ue.useState('');
  const [cari, setCari] = ue.useState('');
  const [q, setQ] = ue.useState('');
  const [murid, setMurid] = ue.useState([]);
  const [memuat, setMemuat] = ue.useState(true);
  const [galat, setGalat] = ue.useState('');
  const [centang, setCentang] = ue.useState([]);
  const [modal, setModal] = ue.useState(null);
  const urutan = ue.useRef(0);

  ue.useEffect(() => { if (filterAwal) setStatus(filterAwal); }, [filterAwal]);
  ue.useEffect(() => {
    const id = ++urutan.current;
    setMemuat(true); setGalat('');
    const qs = [['class_id', kelas], ['status_penugasan', status], ['guru_uid', guru], ['q', q]].filter(([, v]) => v)
      .map(([k, v]) => `&${k}=${encodeURIComponent(v)}`).join('');
    __gwAmbil('guru_wali_murid', qs)
      .then((j) => { if (id === urutan.current) { setMurid(j.murid || []); setCentang((c) => c.filter((x) => (j.murid || []).some((m) => m.id === x))); } })
      .catch((e) => { if (id === urutan.current) { setMurid([]); setGalat(e.message); } })
      .finally(() => { if (id === urutan.current) setMemuat(false); });
  }, [kelas, status, guru, q, versi]);

  const semua = murid.length > 0 && murid.every((m) => centang.includes(m.id));
  const tog = (id) => setCentang((c) => (c.includes(id) ? c.filter((x) => x !== id) : [...c, id]));
  const terlalu = centang.length > 200;

  const bar = h('div', { className: K.bar },
    h('div', { className: K.barKiri },
      h('select', { className: K.select, value: kelas, onChange: (e) => setKelas(e.target.value) },
        h('option', { value: '' }, 'Semua kelas'), ...kelasList.map((c) => h('option', { key: c.id, value: c.id }, c.name || c.id))),
      h('select', { className: K.select, value: status, onChange: (e) => setStatus(e.target.value) },
        h('option', { value: 'SEMUA' }, 'Semua murid aktif'), h('option', { value: 'BELUM' }, 'Belum punya guru wali'),
        h('option', { value: 'SUDAH' }, 'Sudah punya guru wali'), h('option', { value: 'YATIM' }, 'Akun guru wali tidak valid')),
      h('select', { className: K.select, value: guru, onChange: (e) => setGuru(e.target.value) },
        h('option', { value: '' }, 'Semua guru wali'), ...(ring.guru || []).map((g) => h('option', { key: g.uid, value: g.uid }, g.nama))),
      h('form', { className: K.barKiri, onSubmit: (e) => { e.preventDefault(); setQ(cari.trim().length >= 3 ? cari.trim() : ''); } },
        h('input', { className: K.input, placeholder: 'Cari nama/NISN (min. 3 huruf)', value: cari, onChange: (e) => setCari(e.target.value) }),
        h('button', { type: 'submit', className: K.btnPutih }, a.jsx(Ui, { size: 14 }), 'Cari'),
        q && h('button', { type: 'button', className: K.btnPutih, onClick: () => { setCari(''); setQ(''); } }, 'Reset'))),
    h('button', { type: 'button', className: K.btnHijau, disabled: !centang.length || terlalu,
      onClick: () => setModal(murid.filter((m) => centang.includes(m.id)).map((m) => ({ id: m.id, name: m.name }))) },
      a.jsx(E4, { size: 14 }), `Tugaskan ke guru… (${centang.length})`));

  let isi;
  if (memuat && !murid.length) isi = h('div', { className: K.spinnerWrap }, h('div', { className: K.spinner }), h('p', { className: K.spinnerTeks }, 'Memuat murid…'));
  else if (galat) isi = h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat);
  else if (!murid.length) isi = h('div', { className: K.kosong }, 'Tidak ada murid aktif yang cocok dengan saringan.');
  else isi = h('div', { className: K.card },
    h('div', { className: K.cardHead }, h('p', { className: K.judulKecil }, `${murid.length} murid · ${centang.length} dipilih`),
      terlalu && h('span', { className: K.badgeMerah }, 'Maksimal 200 murid per simpan')),
    h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
      h('thead', { className: K.thead }, h('tr', null,
        h('th', { className: K.th }, h('input', { type: 'checkbox', className: G.cek, checked: semua, 'aria-label': 'Pilih semua',
          onChange: () => setCentang(semua ? centang.filter((x) => !murid.some((m) => m.id === x)) : [...new Set([...centang, ...murid.map((m) => m.id)])]) })),
        h('th', { className: K.th }, 'Murid'), h('th', { className: K.th }, 'Kelas'), h('th', { className: K.th }, 'Guru wali'), h('th', { className: K.th }, 'Sejak'))),
      h('tbody', { className: K.tbody }, ...murid.map((m) => h('tr', { key: m.id, className: centang.includes(m.id) ? G.trPilih : K.tr },
        h('td', { className: K.td }, h('input', { type: 'checkbox', className: G.cek, checked: centang.includes(m.id), onChange: () => tog(m.id) })),
        h('td', { className: K.tdNama }, h('span', { className: K.namaBtn }, m.name), h('span', { className: K.nisn }, `NISN ${m.nisn || '-'}`)),
        h('td', { className: K.td }, m.class_name),
        h('td', { className: K.td }, m.penugasan
          ? h('div', { className: G.baris }, m.penugasan.guru_nama, m.penugasan.guru_tidak_valid && h('span', { className: K.badgeMerah }, 'akun tidak valid'))
          : h('span', { className: K.badgeAbu }, 'Belum ada')),
        h('td', { className: G.tdNowrap }, m.penugasan ? __pmTanggal(m.penugasan.tanggal_mulai) : '-')))))));

  return h('div', { className: K.wrap }, bar, isi,
    modal && a.jsx(__gwModalTugaskan, {
      murid: modal, guruList: ring.guru || [], kuota: ring.kuota,
      judul: 'Tugaskan guru wali', onTutup: () => setModal(null),
      onSelesai: (j) => { setModal(null); setCentang([]); onPesan(__gwPesanSimpan(j)); onUbah(); },
    }));
}

// Tab Impor: template, pratinjau, simpan
function __gwTabImpor({ kelasList, onUbah }) {
  const K = __pmK;
  const G = __gwK;
  const h = __pmH;
  const [kelas, setKelas] = ue.useState('');
  const [tanggal, setTanggal] = ue.useState(__gwHariIni());
  const [baris, setBaris] = ue.useState(null);
  const [namaFile, setNamaFile] = ue.useState('');
  const [hasil, setHasil] = ue.useState(null);
  const [ganti, setGanti] = ue.useState(false);
  const [alasan, setAlasan] = ue.useState('');
  const [catatan, setCatatan] = ue.useState('');
  const [sibuk, setSibuk] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const [selesai, setSelesai] = ue.useState(false);

  const unduh = async () => {
    setGalat('');
    try {
      const j = await __gwAmbil('guru_wali_template', kelas ? `&class_id=${encodeURIComponent(kelas)}` : '');
      __pmUnduh([j.header, ...j.baris], `Template-Guru-Wali-${String(j.label || 'Semua').replace(/[^A-Za-z0-9_-]+/g, '-')}.xlsx`, 'Guru Wali');
    } catch (e) { setGalat(e.message); }
  };
  const pratinjau = async (b, tgl) => {
    setSibuk(true); setGalat('');
    try {
      const r = await __gwKirim('guru_wali_impor', { baris: b, pratinjau: true, tanggal_mulai: tgl });
      if (!r.ok) throw new Error(__gwGalat(r));
      setHasil(r.json); setSelesai(false);
    } catch (e) { setGalat(e.message); setHasil(null); } finally { setSibuk(false); }
  };
  const pilihFile = async (f) => {
    setGalat(''); setHasil(null); setSelesai(false); setGanti(false);
    try {
      const rows = await __gwBacaFile(f);
      const norm = (v) => String(v || '').toLowerCase().replace(/\s+/g, ' ').trim();
      const head = rows[0].map(norm);
      const iN = head.indexOf('nisn');
      let iE = head.indexOf('email guru wali'); if (iE < 0) iE = head.findIndex((x) => x.includes('email'));
      let iP = head.indexOf('nip guru wali'); if (iP < 0) iP = head.findIndex((x) => x.startsWith('nip'));
      if (iN < 0) throw new Error('Kolom "NISN" tidak ditemukan di baris pertama. Gunakan template dari tombol Unduh Template.');
      if (iE < 0 && iP < 0) throw new Error('Kolom "Email Guru Wali" atau "NIP Guru Wali" tidak ditemukan di baris pertama.');
      const b = [];
      rows.slice(1).forEach((r, i) => {
        const x = { baris: i + 2, nisn: String(r[iN] || '').trim(), email: iE >= 0 ? String(r[iE] || '').trim() : '', nip: iP >= 0 ? String(r[iP] || '').trim() : '' };
        if (x.nisn || x.email || x.nip) b.push(x);
      });
      if (!b.length) throw new Error('File tidak berisi baris data.');
      if (b.length > 1500) throw new Error('File terlalu besar: maksimal 1.500 baris data.');
      setBaris(b); setNamaFile(f.name);
      await pratinjau(b, tanggal);
    } catch (e) { setGalat(e.message); setBaris(null); }
  };
  const gantiTanggal = (t) => { setTanggal(t); if (baris && !selesai) pratinjau(baris, t); };
  const simpan = async () => {
    setSibuk(true); setGalat('');
    try {
      const r = await __gwKirim('guru_wali_impor', { baris, pratinjau: false, tanggal_mulai: tanggal, ganti, alasan, catatan, nama_file: namaFile });
      if (!r.ok) throw new Error(__gwGalat(r));
      setHasil(r.json); setSelesai(true); onUbah();
    } catch (e) { setGalat(e.message); } finally { setSibuk(false); }
  };

  const rk = hasil ? hasil.ringkasan : null;
  const jmlSimpan = rk ? rk.BARU + (ganti ? rk.GANTI : 0) : 0;
  const LABEL = { BARU: 'Baru', GANTI: 'Ganti', SAMA: 'Sama', KOSONG: 'Kosong', GALAT: 'Galat' };
  const WARNA = { BARU: K.badgeHijau, GANTI: K.badgeBiru, SAMA: K.badgeAbu, KOSONG: K.badgeAbu, GALAT: K.badgeMerah };
  const ket = (b) => b.pesan || (b.status === 'GANTI' ? `Mengganti ${b.guru_lama_nama} (sejak ${__pmTanggal(b.tanggal_mulai_lama)})` : '');
  const kuota = hasil && Array.isArray(hasil.peringatan_kuota) ? hasil.peringatan_kuota : [];

  return h('div', { className: K.wrap },
    h('div', { className: K.card },
      h('div', { className: K.cardHead }, h('p', { className: K.subjudul }, 'Impor pembagian guru wali dari Excel')),
      h('div', { className: K.cardBody },
        h('p', { className: K.teks }, 'Unduh template, isi kolom "Email Guru Wali" (atau "NIP Guru Wali" bila email kosong) untuk setiap murid, lalu unggah. Murid dicocokkan lewat NISN. Baris tanpa email dan NIP dilewati dan tidak mengubah guru wali yang ada. Pratinjau tidak menyimpan apa pun.'),
        h('div', { className: K.bar },
          h('div', { className: K.barKiri },
            h('select', { className: K.select, value: kelas, onChange: (e) => setKelas(e.target.value) },
              h('option', { value: '' }, 'Semua kelas'), ...kelasList.map((c) => h('option', { key: c.id, value: c.id }, c.name || c.id))),
            h('button', { type: 'button', className: K.btnPutih, onClick: unduh }, a.jsx(Ti, { size: 14 }), 'Unduh Template')),
          h('div', { className: K.barKiri },
            h('label', { className: K.teksKecil }, 'Tanggal mulai'),
            h('input', { type: 'date', className: K.input, value: tanggal, max: __gwHariIni(), onChange: (e) => gantiTanggal(e.target.value) }))),
        h('input', { type: 'file', accept: '.xlsx,.xls,.csv', className: K.field, disabled: sibuk,
          onChange: (e) => { const f = e.target.files && e.target.files[0]; if (f) pilihFile(f); e.target.value = ''; } }),
        sibuk && h('p', { className: K.spinnerTeks }, 'Memproses…'),
        galat && h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat))),
    hasil && h('div', { className: K.card },
      h('div', { className: K.cardHead },
        h('div', null, h('p', { className: K.subjudul }, selesai ? 'Hasil impor' : 'Pratinjau'), h('p', { className: K.teksKecil }, namaFile)),
        h('div', { className: K.ringkas }, ...Object.keys(LABEL).map((k) => h('span', { key: k, className: K.chip }, `${LABEL[k]}: ${rk[k]}`)))),
      h('div', { className: K.cardBody },
        selesai && h('div', { className: G.ok }, `Tersimpan: ${hasil.tersimpan.dibuat} penugasan baru, ${hasil.tersimpan.diganti} guru wali diganti.`),
        kuota.length > 0 && h('div', { className: K.peringatan },
          `${selesai ? 'Melebihi' : 'Akan melebihi'} ambang murid dampingan: ${kuota.map((x) => `${x.nama} (${x.jumlah}/${x.kuota})`).join(', ')}. Penyimpanan tetap diizinkan.`),
        !selesai && rk.GANTI > 0 && h('div', { className: K.info },
          h('label', { className: G.baris },
            h('input', { type: 'checkbox', className: G.cek, checked: ganti, onChange: (e) => setGanti(e.target.checked) }),
            h('span', { className: K.kvNilai }, `Ganti guru wali yang sudah ada (${rk.GANTI} murid). Tanpa centang, baris "Ganti" dilewati.`)),
          ganti && h('div', { className: K.grid2 },
            h('div', null, h('label', { className: K.label }, 'Alasan penggantian'), a.jsx(__gwPilihAlasan, { nilai: alasan, onUbah: setAlasan, tanpaNonaktif: true })),
            h('div', null, h('label', { className: K.label }, alasan === 'LAINNYA' ? 'Catatan (wajib)' : 'Catatan (opsional)'),
              h('input', { className: K.field, maxLength: 500, value: catatan, onChange: (e) => setCatatan(e.target.value) })))),
        h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
          h('thead', { className: K.thead }, h('tr', null,
            h('th', { className: K.th }, 'Baris'), h('th', { className: K.th }, 'NISN'), h('th', { className: K.th }, 'Murid'),
            h('th', { className: K.th }, 'Guru wali'), h('th', { className: K.th }, 'Status'), h('th', { className: K.th }, 'Keterangan'))),
          h('tbody', { className: K.tbody }, ...hasil.baris.map((b) => h('tr', { key: b.baris, className: K.tr },
            h('td', { className: K.td }, b.baris), h('td', { className: K.td }, b.nisn || '-'),
            h('td', { className: K.td }, b.nama_murid ? `${b.nama_murid}${b.kelas ? ` (${b.kelas})` : ''}` : '-'),
            h('td', { className: K.td }, b.guru_nama || '-'),
            h('td', { className: K.td }, h('span', { className: WARNA[b.status] || K.badgeAbu }, LABEL[b.status] || b.status),
              selesai && b.disimpan && h('span', { className: K.nisn }, 'tersimpan')),
            h('td', { className: b.status === 'GALAT' ? K.daftarPesanMerah : K.daftarPesan }, ket(b))))))),
        !selesai && h('div', { className: K.bar },
          h('p', { className: K.teksKecil }, `${jmlSimpan} baris akan disimpan.`),
          h('button', { type: 'button', className: K.btnHijau, disabled: sibuk || jmlSimpan === 0 || (ganti && rk.GANTI > 0 && !alasan), onClick: simpan },
            sibuk ? 'Menyimpan…' : `Simpan ${jmlSimpan} baris`)))));
}

// Tab Riwayat: semua penugasan + batalkan
function __gwTabRiwayat({ guruFilter, versi, onHapusFilter, onUbah, onPesan }) {
  const K = __pmK;
  const G = __gwK;
  const h = __pmH;
  const [cari, setCari] = ue.useState('');
  const [q, setQ] = ue.useState('');
  const [batal, setBatal] = ue.useState(false);
  const [hal, setHal] = ue.useState(1);
  const [data, setData] = ue.useState(null);
  const [galat, setGalat] = ue.useState('');
  const [modal, setModal] = ue.useState(null);

  ue.useEffect(() => { setHal(1); }, [guruFilter, q, batal]);
  ue.useEffect(() => {
    let henti = false;
    setGalat('');
    const qs = `&halaman=${hal}${batal ? '&termasuk_batal=1' : ''}${q ? `&q=${encodeURIComponent(q)}` : ''}${guruFilter ? `&guru_uid=${encodeURIComponent(guruFilter.uid)}` : ''}`;
    __gwAmbil('guru_wali_riwayat', qs).then((j) => { if (!henti) setData(j); }).catch((e) => { if (!henti) setGalat(e.message); });
    return () => { henti = true; };
  }, [hal, batal, q, guruFilter, versi]);

  const baris = data ? data.baris : [];
  const jmlHal = data ? Math.max(1, Math.ceil(data.total / data.per_halaman)) : 1;
  const bar = h('div', { className: K.bar },
    h('form', { className: K.barKiri, onSubmit: (e) => { e.preventDefault(); setQ(cari.trim()); } },
      h('input', { className: K.input, placeholder: 'Cari murid, NISN, atau guru', value: cari, onChange: (e) => setCari(e.target.value) }),
      h('button', { type: 'submit', className: K.btnPutih }, a.jsx(Ui, { size: 14 }), 'Cari'),
      guruFilter && h('button', { type: 'button', className: K.btnPutih, onClick: onHapusFilter }, `Guru: ${guruFilter.nama} ✕`)),
    h('label', { className: G.baris },
      h('input', { type: 'checkbox', className: G.cek, checked: batal, onChange: (e) => setBatal(e.target.checked) }),
      h('span', { className: K.teksKecil }, 'Tampilkan yang dibatalkan')));

  let isi;
  if (galat) isi = h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat);
  else if (!data) isi = h('div', { className: K.spinnerWrap }, h('div', { className: K.spinner }));
  else if (!baris.length) isi = h('div', { className: K.kosong }, 'Belum ada riwayat penugasan.');
  else isi = h('div', { className: K.card },
    h('div', { className: K.cardHead }, h('p', { className: K.judulKecil }, `${data.total} penugasan`),
      jmlHal > 1 && h('div', { className: K.barKiri },
        h('button', { type: 'button', className: K.btnPutih, disabled: hal <= 1, onClick: () => setHal(hal - 1) }, '‹'),
        h('span', { className: K.teksKecil }, `Halaman ${hal}/${jmlHal}`),
        h('button', { type: 'button', className: K.btnPutih, disabled: hal >= jmlHal, onClick: () => setHal(hal + 1) }, '›'))),
    h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
      h('thead', { className: K.thead }, h('tr', null,
        h('th', { className: K.th }, 'Murid'), h('th', { className: K.th }, 'Guru wali'), h('th', { className: K.th }, 'Mulai'),
        h('th', { className: K.th }, 'Selesai'), h('th', { className: K.th }, 'Status'), h('th', { className: K.th }, 'Keterangan'), h('th', { className: K.th }, ''))),
      h('tbody', { className: K.tbody }, ...baris.map((r) => h('tr', { key: r.id, className: K.tr },
        h('td', { className: K.tdNama }, h('span', { className: K.namaBtn }, r.nama_murid),
          h('span', { className: K.nisn }, `${r.kelas_kini || '-'}${r.kelas_saat_mulai && r.kelas_saat_mulai !== r.kelas_kini ? ` (saat mulai: ${r.kelas_saat_mulai})` : ''}`)),
        h('td', { className: K.td }, r.guru_nama, r.akun_dihapus && h('span', { className: K.nisn }, '(akun dihapus)')),
        h('td', { className: G.tdNowrap }, __pmTanggal(r.tanggal_mulai)),
        h('td', { className: G.tdNowrap }, r.tanggal_selesai ? __pmTanggal(r.tanggal_selesai) : '-'),
        h('td', { className: K.td }, __gwStatus(r.efektif_status)),
        h('td', { className: G.tdKecil },
          r.alasan_label && h('div', null, r.alasan_label + (r.catatan_selesai ? ` — ${r.catatan_selesai}` : '')),
          r.status === 'DIBATALKAN' && h('div', null, `Dibatalkan ${r.dibatalkan_oleh_nama || ''}: ${r.alasan_batal || ''}`),
          h('div', null, `Dibuat ${r.dibuat_oleh_nama}${r.sumber === 'IMPOR' ? ' (impor)' : ''}`)),
        h('td', { className: K.td }, r.status === 'AKTIF' && h('button', { type: 'button', className: K.btnPutih, onClick: () => setModal(r) },
          a.jsx(wue, { size: 14 }), 'Batalkan'))))))));

  return h('div', { className: K.wrap }, bar, isi,
    modal && a.jsx(__gwModalBatal, {
      baris: modal, onTutup: () => setModal(null),
      onSelesai: (j) => {
        setModal(null);
        onPesan(j.dipulihkan ? { jenis: 'ok', teks: `Penugasan dibatalkan. Guru wali sebelumnya (${j.dipulihkan.guru_nama}) dipulihkan.` }
          : { jenis: j.pesan ? 'warn' : 'ok', teks: j.pesan || 'Penugasan dibatalkan.' });
        onUbah();
      },
    }));
}

// Halaman Guru Wali (ADMIN/KEPALA_SEKOLAH)
function __gwKelola() {
  const K = __pmK;
  const G = __gwK;
  const h = __pmH;
  const [tab, setTab] = ue.useState('guru');
  const [ring, setRing] = ue.useState(null);
  const [galat, setGalat] = ue.useState('');
  const [pesan, setPesan] = ue.useState(null);
  const [versi, setVersi] = ue.useState(0);
  const [kelasList, setKelasList] = ue.useState([]);
  const [filterMurid, setFilterMurid] = ue.useState('');
  const [guruRiwayat, setGuruRiwayat] = ue.useState(null);
  const [tutupBuka, setTutupBuka] = ue.useState(false);
  const [sibuk, setSibuk] = ue.useState(false);

  const muat = ue.useCallback(async () => {
    setGalat('');
    try { setRing(await __gwAmbil('guru_wali_ringkasan')); } catch (e) { setGalat(e.message); }
  }, []);
  ue.useEffect(() => { muat(); }, [muat, versi]);
  ue.useEffect(() => {
    __gwAmbil('get_classes').then((j) => setKelasList(Array.isArray(j) ? j : [])).catch(() => setKelasList([]));
  }, []);
  const ubah = () => setVersi((v) => v + 1);
  const keTab = (t, filter) => { setTab(t); if (filter !== undefined) setFilterMurid(filter); setPesan(null); };
  const tutupNonaktif = async () => {
    setSibuk(true);
    try {
      const r = await __gwKirim('guru_wali_tutup_nonaktif', {});
      if (!r.ok) throw new Error(__gwGalat(r));
      setPesan({ jenis: 'ok', teks: `${r.json.ditutup} penugasan murid tidak aktif ditutup.` }); setTutupBuka(false); ubah();
    } catch (e) { setPesan({ jenis: 'galat', teks: e.message }); } finally { setSibuk(false); }
  };

  const kepala = a.jsx(zi, {
    title: 'Guru Wali',
    description: 'Penugasan murid dampingan: setiap murid didampingi satu guru wali sejak terdaftar hingga lulus, lintas kelas dan tahun ajaran.',
    category: 'Bimbingan Guru Wali',
    color: 'blue',
    illustration: a.jsx(t0, { size: 70, className: K.ilustrasi }),
  });
  if (galat && !ring) return h('div', { className: K.halaman }, kepala, h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat));
  if (!ring) return h('div', { className: K.halaman }, kepala, h('div', { className: K.spinnerWrap }, h('div', { className: K.spinner }), h('p', { className: K.spinnerTeks }, 'Memuat…')));

  const stat = (label, nilai, merah, aksi) => h('div', { key: label, className: K.stat },
    h('p', { className: K.statLabel }, label), h('p', { className: merah ? K.statNilaiMerah : K.statNilai }, nilai),
    aksi && h('button', { type: 'button', className: G.link, onClick: aksi[1] }, aksi[0]));
  const TABS = [
    { id: 'guru', label: 'Per Guru' }, { id: 'murid', label: 'Murid' }, { id: 'impor', label: 'Impor Excel' }, { id: 'riwayat', label: 'Riwayat' },
  ];
  const kelasPesan = pesan ? (pesan.jenis === 'ok' ? G.ok : pesan.jenis === 'warn' ? K.peringatan : K.galat) : '';
  return h('div', { className: K.halaman },
    kepala,
    h('div', { className: K.grid4 },
      stat('Murid aktif', ring.total_murid_aktif),
      stat('Belum punya guru wali', ring.murid_tanpa_guru_wali, ring.murid_tanpa_guru_wali > 0,
        ring.murid_tanpa_guru_wali > 0 ? ['Lihat daftar', () => keTab('murid', 'BELUM')] : null),
      stat('Guru', (ring.guru || []).length),
      stat('Ambang per guru', ring.kuota)),
    ring.penugasan_menggantung > 0 && h('div', { className: K.peringatan },
      h('div', { className: K.bar },
        h('span', null, `${ring.penugasan_menggantung} penugasan masih terbuka untuk murid yang sudah tidak aktif (lulus/keluar/pindah).`),
        tutupBuka
          ? h('div', { className: K.barKiri },
            h('button', { type: 'button', className: K.btnHijau, disabled: sibuk, onClick: tutupNonaktif }, sibuk ? 'Memproses…' : 'Ya, tutup semua'),
            h('button', { type: 'button', className: K.btnPutih, disabled: sibuk, onClick: () => setTutupBuka(false) }, 'Batal'))
          : h('button', { type: 'button', className: K.btnPutih, onClick: () => setTutupBuka(true) }, 'Tutup sekarang'))),
    ring.penugasan_yatim > 0 && h('div', { className: K.peringatan },
      h('div', { className: K.bar },
        h('span', null, `${ring.penugasan_yatim} murid didampingi akun yang sudah dihapus atau bukan guru lagi.`),
        h('button', { type: 'button', className: K.btnPutih, onClick: () => keTab('murid', 'YATIM') }, 'Lihat murid'))),
    pesan && h('div', { className: kelasPesan }, pesan.teks),
    h('div', { className: K.bar },
      h('div', { className: K.tabWrap }, ...TABS.map((t) => h('button', { key: t.id, type: 'button', className: tab === t.id ? K.tabOn : K.tabOff, onClick: () => keTab(t.id) }, t.label))),
      h('button', { type: 'button', className: K.btnPutih, onClick: ubah }, a.jsx(po, { size: 14 }), 'Muat ulang')),
    tab === 'guru' && a.jsx(__gwTabGuru, { ring, versi, onUbah: ubah, onPesan: setPesan, onRiwayat: (g) => { setGuruRiwayat(g); keTab('riwayat'); } }),
    tab === 'murid' && a.jsx(__gwTabMurid, { ring, kelasList, versi, filterAwal: filterMurid, onUbah: ubah, onPesan: setPesan }),
    tab === 'impor' && a.jsx(__gwTabImpor, { kelasList, onUbah: ubah }),
    tab === 'riwayat' && a.jsx(__gwTabRiwayat, { guruFilter: guruRiwayat, versi, onHapusFilter: () => setGuruRiwayat(null), onUbah: ubah, onPesan: setPesan }));
}

// Halaman Murid Dampingan (GURU)
function __gwDampingan({ onNavigate }) {
  const K = __pmK;
  const h = __pmH;
  const [data, setData] = ue.useState(null);
  const [galat, setGalat] = ue.useState('');
  const [cari, setCari] = ue.useState('');
  const muat = ue.useCallback(async () => {
    setGalat('');
    try { setData(await __gwAmbil('guru_wali_dampingan_saya')); } catch (e) { setGalat(e.message); }
  }, []);
  ue.useEffect(() => { muat(); }, [muat]);

  const kepala = a.jsx(zi, {
    title: 'Murid Dampingan',
    description: 'Murid yang Anda dampingi sebagai guru wali, dari semua kelas. Klik nama untuk membuka Profil Murid 360°.',
    category: 'Bimbingan Guru Wali',
    color: 'blue',
    illustration: a.jsx(wa, { size: 70, className: K.ilustrasi }),
  });
  let isi;
  if (galat) isi = h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat);
  else if (!data) isi = h('div', { className: K.spinnerWrap }, h('div', { className: K.spinner }), h('p', { className: K.spinnerTeks }, 'Memuat murid dampingan…'));
  else if (!data.murid.length) isi = h('div', { className: K.kosong }, 'Anda belum memiliki murid dampingan. Penugasan guru wali diatur oleh Kepala Sekolah/Admin.');
  else {
    const kunci = cari.trim().toLowerCase();
    const murid = data.murid.filter((m) => !kunci || `${m.name} ${m.nisn} ${m.class_name}`.toLowerCase().includes(kunci));
    const p = data.periode_aktif;
    isi = h('div', { className: K.card },
      h('div', { className: K.cardHead },
        h('p', { className: K.judulKecil }, `${data.murid.length} murid dampingan · kehadiran & asesmen ${p.academic_year} ${p.semester === 'GENAP' ? 'Genap' : 'Ganjil'}`),
        h('input', { className: K.input, placeholder: 'Cari nama/NISN/kelas', value: cari, onChange: (e) => setCari(e.target.value) })),
      h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
        h('thead', { className: K.thead }, h('tr', null,
          h('th', { className: K.th }, 'Murid'), h('th', { className: K.th }, 'Kelas'), h('th', { className: K.th }, 'Didampingi sejak'),
          h('th', { className: K.thTengah }, 'Biodata'), h('th', { className: K.thTengah }, 'Asesmen'),
          h('th', { className: K.thTengah }, 'Poin'), h('th', { className: K.thTengah }, '% Hadir'))),
        h('tbody', { className: K.tbody }, ...murid.map((m) => h('tr', { key: m.id, className: K.tr },
          h('td', { className: K.tdNama },
            h('button', { type: 'button', className: K.namaBtn, onClick: () => __pmBuka(m.id, 'murid-dampingan', onNavigate) }, m.name),
            h('span', { className: K.nisn }, `NISN ${m.nisn || '-'}`)),
          h('td', { className: K.td }, m.class_name),
          h('td', { className: __gwK.tdNowrap }, __pmTanggal(m.tanggal_mulai)),
          h('td', { className: K.tdTengah }, h('span', { className: m.ada_biodata ? K.badgeHijau : K.badgeAbu }, m.ada_biodata ? '✓' : '–')),
          h('td', { className: K.tdTengah }, h('span', { className: m.ada_asesmen ? K.badgeHijau : K.badgeAbu }, m.ada_asesmen ? '✓' : '–')),
          h('td', { className: K.tdTengah }, h('span', { className: m.total_violation_points > 0 ? K.badgeMerah : K.badgeAbu }, m.total_violation_points)),
          h('td', { className: K.tdTengah }, m.persen_hadir === null ? '-' : `${__pmAngka(m.persen_hadir)}%`)))))),
      !murid.length && h('div', { className: K.kosongKecil }, 'Tidak ada murid yang cocok.'));
  }
  return h('div', { className: K.halaman }, kepala,
    h('div', { className: K.bar }, h('span', null), h('button', { type: 'button', className: K.btnPutih, onClick: () => { setData(null); muat(); } }, a.jsx(po, { size: 14 }), 'Muat ulang')),
    isi);
}

// Kartu "Guru Wali" di Profil Murid 360° (tanpa hook)
function __gwKartuProfil({ data }) {
  const K = __pmK;
  const h = __pmH;
  const gw = data.guru_wali || { aktif: null, riwayat: [] };
  const riwayat = gw.riwayat || [];
  return h('div', { className: K.card },
    h('div', { className: K.cardHead }, h('p', { className: K.subjudul }, 'Guru Wali'),
      gw.aktif ? h('span', { className: K.badgeHijau }, 'Aktif') : h('span', { className: K.badgeAbu }, 'Belum ditetapkan')),
    h('div', { className: K.cardBody },
      gw.aktif
        ? h('div', { className: K.kv },
          h('div', null, h('span', { className: K.kvLabel }, 'Guru wali'), h('span', { className: K.kvNilai }, gw.aktif.guru_nama)),
          h('div', null, h('span', { className: K.kvLabel }, 'Didampingi sejak'), h('span', { className: K.kvNilai }, __pmTanggal(gw.aktif.tanggal_mulai))))
        : h('div', { className: K.info }, 'Murid ini belum memiliki guru wali. Penugasan diatur Kepala Sekolah/Admin di menu Guru Wali.'),
      riwayat.length > 1 && h('p', { className: K.judulKecil }, 'Riwayat guru wali'),
      riwayat.length > 1 && h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
        h('thead', { className: K.thead }, h('tr', null,
          h('th', { className: K.th }, 'Guru wali'), h('th', { className: K.th }, 'Mulai'), h('th', { className: K.th }, 'Selesai'),
          h('th', { className: K.th }, 'Status'), h('th', { className: K.th }, 'Alasan selesai'))),
        h('tbody', { className: K.tbody }, ...riwayat.map((r, i) => h('tr', { key: i, className: K.tr },
          h('td', { className: K.td }, r.guru_nama, r.akun_dihapus && h('span', { className: K.nisn }, '(akun dihapus)')),
          h('td', { className: __gwK.tdNowrap }, __pmTanggal(r.tanggal_mulai)),
          h('td', { className: __gwK.tdNowrap }, r.tanggal_selesai ? __pmTanggal(r.tanggal_selesai) : '-'),
          h('td', { className: K.td }, __gwStatus(r.efektif_status)),
          h('td', { className: __gwK.tdKecil }, r.alasan_label ? r.alasan_label + (r.catatan_selesai ? ` — ${r.catatan_selesai}` : '') : '-'))))))));
}
/* eslint-enable no-undef */

const FUNGSI = [__gwKirim, __gwGalat, __gwAmbil, __gwHariIni, __gwBacaFile, __gwPesanSimpan, __gwStatus, __gwPilihAlasan, __gwModal,
  __gwModalTugaskan, __gwModalAkhiri, __gwModalBatal, __gwTabGuru, __gwTabMurid, __gwTabImpor, __gwTabRiwayat,
  __gwKelola, __gwDampingan, __gwKartuProfil];
const KOMPONEN_SRC = `const __gwK=${JSON.stringify(KELAS)};const __gwAlasan=${JSON.stringify(__gwAlasan)};${FUNGSI.map((f) => f.toString()).join('')}`;

const GRUP_SIDEBAR = '{title:"Bimbingan Guru Wali",items:['
  + '{id:"guru-wali",label:"Guru Wali",icon:a.jsx(t0,{size:20}),roles:["ADMIN","KEPALA_SEKOLAH"]},'
  + '{id:"murid-dampingan",label:"Murid Dampingan",icon:a.jsx(wa,{size:20}),roles:["GURU"]}]},';
const SETELAH_HARI = 'Sekolah 5 hari: Sabtu tidak dihitung."})]})';
const FIELD_KUOTA = `,a.jsxs("div",{className:"${KELAS.kuotaWrap}",children:[`
  + `a.jsxs("label",{className:"${KELAS.kuotaLabel}",children:[a.jsx(t0,{size:12})," Ambang Murid Dampingan per Guru Wali"]}),`
  + `a.jsx("input",{type:"number",min:1,max:100,step:1,value:String(ie.guru_wali_kuota_peringatan??"20"),onChange:Se=>le({...ie,guru_wali_kuota_peringatan:Se.target.value}),className:"${KELAS.kuotaInput}"}),`
  + `a.jsx("p",{className:"${KELAS.kuotaKet}",children:"Guru wali dengan murid dampingan melebihi angka ini ditandai di menu Guru Wali. Penugasan tetap diizinkan (1–100, bawaan 20)."})]})`;

const patches = [
  {
    id: 'G0 komponen Guru Wali',
    from: 'function dot({user:e,onNavigate:t,activeSemester:n}){',
    to: `${KOMPONEN_SRC}function dot({user:e,onNavigate:t,activeSemester:n}){`,
  },
  {
    id: 'G1+G2 grup sidebar Bimbingan Guru Wali',
    from: '{title:"Umum & Ekskul"',
    to: GRUP_SIDEBAR + '{title:"Umum & Ekskul"',
  },
  {
    id: 'G3+G4 router halaman',
    from: 'e==="profil-murid"&&a.jsx(__pmProfil,{user:o,onNavigate:be=>t(be)}),',
    to: 'e==="profil-murid"&&a.jsx(__pmProfil,{user:o,onNavigate:be=>t(be)}),'
      + 'e==="guru-wali"&&a.jsx(__gwKelola,{user:o}),'
      + 'e==="murid-dampingan"&&a.jsx(__gwDampingan,{user:o,onNavigate:be=>t(be)}),',
  },
  {
    id: 'G3+G4 daftar halaman dikenal',
    from: '"bk-profil-murid","profil-murid","portal-nilai"',
    to: '"bk-profil-murid","profil-murid","guru-wali","murid-dampingan","portal-nilai"',
  },
  {
    id: 'G5a kepala Profil Murid: baris guru wali',
    from: "        h('span', { className: m.total_poin > 0 ? K.badgeMerah : K.badgeAbu }, `${m.total_poin} poin pelanggaran`))),",
    to: "        h('span', { className: m.total_poin > 0 ? K.badgeMerah : K.badgeAbu }, `${m.total_poin} poin pelanggaran`)),"
      + " h('p', { className: K.teksKecil }, data.guru_wali && data.guru_wali.aktif"
      + " ? `Guru wali: ${data.guru_wali.aktif.guru_nama} (sejak ${__pmTanggal(data.guru_wali.aktif.tanggal_mulai)})` : 'Guru wali: belum ditetapkan')),",
  },
  {
    id: 'G5b kartu Guru Wali di Profil Murid',
    from: '    kepala, bagianBiodata, bagianAsesmen, bagianKarir,',
    to: '    kepala, bagianBiodata, a.jsx(__gwKartuProfil, { data }), bagianAsesmen, bagianKarir,',
  },
  {
    id: 'G6a Dashboard Kelas Saya: per_siswa menyimpan guru wali',
    from: 'Y.per_siswa.map(me=>({student_id:me.student_id,studentName:me.student_name,',
    to: 'Y.per_siswa.map(me=>({student_id:me.student_id,guruWaliNama:me.guru_wali_nama||"",studentName:me.student_name,',
  },
  {
    id: 'G6b Dashboard Kelas Saya: judul kolom Guru Wali',
    from: 'a.jsx("th",{className:"px-6 py-4",children:"Nama Siswa"}),a.jsx("th",{className:"px-6 py-4 text-center",children:"Hadir"})',
    to: `a.jsx("th",{className:"px-6 py-4",children:"Nama Siswa"}),a.jsx("th",{className:"${KELAS.thGuru}",children:"Guru Wali"}),a.jsx("th",{className:"px-6 py-4 text-center",children:"Hadir"})`,
  },
  {
    id: 'G6c Dashboard Kelas Saya: sel Guru Wali',
    from: 'className:"font-bold text-slate-700 hover:text-emerald-600 text-left",children:z.studentName})]})}),',
    to: `className:"font-bold text-slate-700 hover:text-emerald-600 text-left",children:z.studentName})]})}),a.jsx("td",{className:"${KELAS.tdGuru}",children:z.guruWaliNama||"–"}),`,
  },
  {
    id: 'G7 Pengaturan: ambang murid dampingan',
    from: SETELAH_HARI,
    to: SETELAH_HARI + FIELD_KUOTA,
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
  const hilang = [...kelas].filter((c) => {
    const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
    return !re.test(css);
  });
  if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));
  console.log(`OK  ${kelas.size} class Tailwind tersedia di CSS bundle`);

  // Komponen hanya boleh memakai kunci __pmK yang ada
  const pmK = /const __pmK=(\{.*?\});/.exec(src);
  if (!pmK) fail('__pmK (patch Profil Murid) tidak ditemukan.');
  const kunciPm = new Set(Object.keys(JSON.parse(pmK[1])));
  const dipakai = new Set([...KOMPONEN_SRC.matchAll(/\bK\.([A-Za-z0-9_]+)/g)].map((m) => m[1]));
  const tidakAda = [...dipakai].filter((k) => !kunciPm.has(k));
  if (tidakAda.length) fail('kunci __pmK tidak ada: ' + tidakAda.join(', '));
  const dipakaiG = new Set([...KOMPONEN_SRC.matchAll(/\b(?:G|__gwK)\.([A-Za-z0-9_]+)/g)].map((m) => m[1]));
  const tidakAdaG = [...dipakaiG].filter((k) => !(k in KELAS));
  if (tidakAdaG.length) fail('kunci __gwK tidak ada: ' + tidakAdaG.join(', '));

  // Identifier yang disisipkan tidak boleh sudah ada; identifier bundle yang dipakai harus ada di scope modul
  for (const nama of ['__gwK', '__gwKelola', '__gwDampingan', '"guru-wali"', '"murid-dampingan"', 'guru_wali_kuota_peringatan']) {
    if (src.includes(nama)) fail(`${nama} sudah ada di ${SRC_NAME}.`);
  }
  const wajib = {
    t0: /[,;\s]t0=Nt\("user-check"/, E4: /[,;\s]E4=Nt\("user-plus"/, fE: /[,;\s]fE=Nt\("history"/, wue: /[,;\s]wue=Nt\("rotate-ccw"/,
    wa: /[,;\s]wa=Nt\("users"/, Ti: /[,;\s]Ti=Nt\("download"/, Z1: /[,;\s]Z1=Nt\("triangle-alert"/, po: /[,;\s]po=Nt\("refresh-cw"/,
    pi: /[,;\s]pi=Nt\("x"/, Ui: /[,;\s]Ui=Nt\("search"/, K$: /[,;\s]K\$=nr[,;]/,
    __pmH: /function __pmH\(/, __pmTanggal: /function __pmTanggal\(/, __pmAngka: /function __pmAngka\(/, __pmBuka: /function __pmBuka\(/,
    C_: /function C_\(/, Jl: /[,;\s]Jl=\{encode_col:/, 'Jl.sheet_to_json': /Jl=\{[^}]*sheet_to_json:/,
    __pmUnduh: /function __pmUnduh\(/, __pmProfil: /function __pmProfil\(/,
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
