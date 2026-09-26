// Patch bundle frontend: Portal Nilai Orang Tua (kelola PIN, cetak kartu akses, saklar portal).
// Spesifikasi: docs/specs/portal-nilai-orang-tua.md (§5)
//  P0 Komponen __ponPanelAksesOrtu + __ponHalamanAdmin + peta class Tailwind (__ponK),
//     disisipkan tepat sebelum komponen __pnkPantauNilaiKelas (patch tahap 1).
//  P1 Tab Nilai (wali kelas): tombol "🔑 Akses Orang Tua" di baris kontrol.
//  P2 Tab Nilai: view `akses` (panel) sebelum pemeriksaan data nilai.
//  P3 Menu ADMIN: item "Portal Nilai Ortu" (portal-nilai) di akhir grup "Manajemen Data".
//  P4 Router halaman: portal-nilai -> __ponHalamanAdmin.
//  P5 Daftar halaman yang dikenal: tambah portal-nilai.
// Prasyarat: patch Pantau Nilai Kelas (apply-pantau-nilai-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-portal-nilai-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-pantaunilai1.js';
const OUT_NAME = 'index-CGWL69P7-portalnilai1.js';
const SRC_SHA256 = '743f21d36caf1c3ddbf1d17e661ac1036dcd8d87f033279660fba7f66fa870ee';
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
// Semua class Tailwind komponen (divalidasi terhadap CSS bundle di bawah).
// ---------------------------------------------------------------------------
const BADGE = 'inline-block text-[10px] font-black px-2 py-0.5 rounded-full';
const TOGGLE = 'px-6 py-2 rounded-xl text-xs font-black uppercase tracking-wider transition-all';
const KELAS = {
  halaman: 'max-w-6xl mx-auto space-y-8 pb-20',
  wrap: 'space-y-6',
  bar: 'flex flex-wrap items-center justify-between gap-3',
  barKiri: 'flex flex-wrap items-center gap-2',
  select: 'px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-600',
  input: 'w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-600',
  btnPutih: 'flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-500 hover:bg-slate-50 transition-all',
  btnHijau: 'flex items-center gap-2 px-4 py-2 bg-emerald-600 border border-emerald-600 rounded-xl text-xs font-black text-white hover:bg-emerald-700 transition-all',
  btnMerah: 'flex items-center gap-2 px-4 py-2 bg-white border border-red-200 rounded-xl text-xs font-black text-red-600 hover:bg-red-50 transition-all',
  btnMati: 'flex items-center gap-2 px-4 py-2 bg-slate-100 border border-slate-200 rounded-xl text-xs font-black text-slate-400 cursor-not-allowed',
  toggleWrap: 'flex bg-slate-100 p-1.5 rounded-2xl w-fit',
  toggleOn: TOGGLE + ' bg-white text-emerald-600 shadow-sm',
  toggleOnMerah: TOGGLE + ' bg-white text-red-600 shadow-sm',
  toggleOff: TOGGLE + ' text-slate-400 hover:text-slate-600',
  card: 'bg-white rounded-[2.5rem] border border-slate-100 overflow-hidden shadow-sm',
  cardHead: 'p-6 border-b border-slate-50 flex flex-wrap items-center justify-between gap-3',
  cardBody: 'p-6 space-y-3',
  judulKecil: 'text-xs font-black text-slate-400 uppercase tracking-widest',
  subjudul: 'text-lg font-black text-slate-800',
  teks: 'text-sm text-slate-500',
  teksKecil: 'text-[11px] font-bold text-slate-400',
  infoHijau: 'p-4 rounded-2xl bg-emerald-50 text-emerald-700 text-sm font-bold',
  infoKuning: 'p-4 rounded-2xl bg-amber-50 text-amber-700 text-sm font-bold',
  statGrid: 'grid grid-cols-2 md:grid-cols-4 gap-4',
  stat: 'bg-white rounded-3xl border border-slate-100 p-5',
  statLabel: 'text-[10px] font-black text-slate-400 uppercase tracking-widest',
  statNilai: 'text-2xl font-black text-slate-800 mt-0.5',
  tabelWrap: 'overflow-x-auto',
  tabel: 'w-full text-left',
  thead: 'bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest',
  th: 'px-4 py-3 whitespace-nowrap',
  tbody: 'divide-y divide-slate-50',
  tr: 'hover:bg-slate-50/50 transition-colors',
  td: 'px-4 py-3 text-sm text-slate-600',
  tdNama: 'px-4 py-3 text-sm font-bold text-slate-700 whitespace-nowrap',
  badgeAbu: BADGE + ' bg-slate-100 text-slate-500',
  badgeMerah: BADGE + ' bg-red-50 text-red-700',
  badgeHijau: BADGE + ' bg-emerald-50 text-emerald-700',
  badgeKuning: BADGE + ' bg-amber-50 text-amber-700',
  kosong: 'py-16 text-center text-slate-400 font-bold text-sm',
  spinnerWrap: 'py-20 text-center space-y-3',
  spinner: 'w-10 h-10 border-4 border-emerald-600 border-t-transparent rounded-full animate-spin mx-auto',
  spinnerTeks: 'text-slate-400 font-bold text-sm',
  galat: 'flex items-center gap-3 p-5 rounded-3xl border border-red-100 bg-red-50 text-red-700 text-sm font-bold',
  checkLabel: 'flex items-center gap-2 text-xs font-black text-slate-500',
};

// ---------------------------------------------------------------------------
// P0 Komponen. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas dari scope modul bundle:
//   a = jsx runtime, ue = React, K$ = URL api/index.php, zi = kartu judul halaman,
//   ikon lucide: po (refresh-cw), Ss (printer), Z1 (triangle-alert), D_ (graduation-cap).
// __ponK = peta class Tailwind (KELAS di atas).
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */
function __ponPanelAksesOrtu({ classId, showClassPicker, isAdmin }) {
  const K = __ponK;
  const API = K$;
  const h = (t, p, ...c) => {
    const { key, ...rest } = p || {};
    if (c.length) rest.children = c.length === 1 ? c[0] : c;
    return a.jsx(t, rest, key);
  };
  const [kelasPilih, setKelasPilih] = ue.useState('');
  const [daftarKelas, setDaftarKelas] = ue.useState([]);
  const kelas = showClassPicker ? kelasPilih : (classId || '');
  const [data, setData] = ue.useState(null);
  const [memuat, setMemuat] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const [pilih, setPilih] = ue.useState({});
  const [prosesSaklar, setProsesSaklar] = ue.useState(false);
  const permintaan = ue.useRef(0);

  const ambilJson = async (url, opsi) => {
    const res = await fetch(url, opsi);
    let j = null;
    try { j = await res.json(); } catch (e) { j = null; }
    if (!res.ok) {
      const pesan = j && j.error ? j.error : `Gagal memuat data (HTTP ${res.status}).`;
      throw new Error(pesan === 'Unauthorized' ? 'Sesi berakhir, silakan login ulang.' : pesan);
    }
    return j;
  };

  ue.useEffect(() => {
    if (!showClassPicker) return undefined;
    let batal = false;
    ambilJson(`${API}?action=get_classes`).then((j) => {
      if (batal) return;
      const list = Array.isArray(j) ? j : [];
      setDaftarKelas(list);
      setKelasPilih((k) => k || (list.length ? list[0].id : ''));
    }).catch(() => {});
    return () => { batal = true; };
  }, [showClassPicker]);

  const muat = ue.useCallback(async () => {
    const id = ++permintaan.current;
    if (showClassPicker && !kelas) return;
    setMemuat(true);
    setGalat('');
    try {
      const j = await ambilJson(`${API}?action=portal_nilai_status${kelas ? `&class_id=${encodeURIComponent(kelas)}` : ''}`);
      if (id === permintaan.current) { setData(j); setPilih({}); }
    } catch (e) {
      if (id === permintaan.current) { setData(null); setGalat(e.message); }
    } finally {
      if (id === permintaan.current) setMemuat(false);
    }
  }, [kelas, showClassPicker]);

  ue.useEffect(() => { muat(); }, [muat]);
  // Setelah tab kartu ditutup, status PIN dimuat ulang
  ue.useEffect(() => {
    const f = () => muat();
    window.addEventListener('focus', f);
    return () => window.removeEventListener('focus', f);
  }, [muat]);

  // PIN tidak boleh lewat URL: kirim form POST ke tab baru
  const cetak = (mode, ids) => {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = `${API}?action=cetak_kartu_akses`;
    form.target = '_blank';
    const tambah = (nama, nilai) => {
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = nama; i.value = nilai;
      form.appendChild(i);
    };
    tambah('class_id', data.class.id);
    tambah('mode', mode);
    ids.forEach((sid) => tambah('student_ids[]', sid));
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
    setTimeout(muat, 2500);
  };

  const ubahSaklar = async (aktif) => {
    if (!aktif && !window.confirm('Nonaktifkan portal nilai? Semua orang tua yang sedang masuk akan keluar.')) return;
    setProsesSaklar(true);
    try {
      await ambilJson(`${API}?action=portal_nilai_set_aktif`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ aktif }),
      });
      await muat();
    } catch (e) {
      setGalat(e.message);
    } finally {
      setProsesSaklar(false);
    }
  };

  const tgl = (d) => { const p = String(d || '').slice(0, 10).split('-'); return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : '-'; };
  const jam = (d) => String(d || '').slice(11, 16);
  const STATUS = {
    BELUM: ['Belum dibuat', K.badgeAbu],
    AKTIF: ['Aktif', K.badgeHijau],
    TERKUNCI: ['Terkunci', K.badgeMerah],
    NISN_KOSONG: ['NISN kosong', K.badgeKuning],
    NISN_GANDA: ['NISN ganda', K.badgeKuning],
  };
  const bisaDipilih = (s) => s.pin_status !== 'NISN_KOSONG' && s.pin_status !== 'NISN_GANDA';

  const siswa = data && Array.isArray(data.students) ? data.students : [];
  const terpilih = siswa.filter((s) => pilih[s.student_id] && bisaDipilih(s)).map((s) => s.student_id);
  const belum = siswa.filter((s) => s.pin_status === 'BELUM');
  const semuaDipilih = siswa.filter(bisaDipilih).length > 0 && siswa.filter(bisaDipilih).every((s) => pilih[s.student_id]);

  const kontrol = h('div', { className: K.bar },
    h('div', { className: K.barKiri },
      showClassPicker && h('select', { className: K.select, value: kelasPilih, onChange: (e) => setKelasPilih(e.target.value) },
        daftarKelas.length === 0 && h('option', { value: '' }, 'Memuat kelas...'),
        daftarKelas.map((c) => h('option', { key: c.id, value: c.id }, c.name || c.id))),
      data && h('span', { className: K.judulKecil }, `Akses Orang Tua — Kelas ${data.class.name || data.class.id}`)),
    h('button', { onClick: muat, className: K.btnPutih }, h(po, { size: 13 }), ' Perbarui'));

  let isi;
  if (memuat && !data) {
    isi = h('div', { className: K.spinnerWrap }, h('div', { className: K.spinner }), h('p', { className: K.spinnerTeks }, 'Memuat status akses...'));
  } else if (galat && !data) {
    isi = h('div', { className: K.galat }, h(Z1, { size: 18 }), h('span', null, galat));
  } else if (!data) {
    isi = showClassPicker && daftarKelas.length === 0 ? null : h('div', { className: K.card }, h('p', { className: K.kosong }, 'Pilih kelas.'));
  } else {
    const ring = data.summary || {};
    const kartu = (label, nilai) => h('div', { className: K.stat }, h('p', { className: K.statLabel }, label), h('p', { className: K.statNilai }, nilai));
    isi = h('div', { className: K.wrap },
      galat && h('div', { className: K.galat }, h(Z1, { size: 18 }), h('span', null, galat)),
      h('div', { className: K.card },
        h('div', { className: K.cardHead },
          h('div', null,
            h('p', { className: K.subjudul }, 'Portal Nilai Orang Tua'),
            h('p', { className: K.teks }, 'Siswa dan orang tua masuk dengan NISN + PIN dari kartu akses untuk melihat nilai, tugas yang tertinggal, dan presensi.')),
          data.is_admin
            ? h('div', { className: K.toggleWrap },
              h('button', { disabled: prosesSaklar, onClick: () => !data.aktif && ubahSaklar(true), className: data.aktif ? K.toggleOn : K.toggleOff }, 'Aktif'),
              h('button', { disabled: prosesSaklar, onClick: () => data.aktif && ubahSaklar(false), className: !data.aktif ? K.toggleOnMerah : K.toggleOff }, 'Nonaktif'))
            : h('span', { className: data.aktif ? K.badgeHijau : K.badgeMerah }, data.aktif ? 'Portal aktif' : 'Portal nonaktif (diatur Admin)')),
        h('div', { className: K.cardBody },
          h('p', { className: data.aktif ? K.infoHijau : K.infoKuning }, data.aktif
            ? 'Portal aktif: orang tua dapat masuk dengan kartu akses.'
            : 'Portal nonaktif: PIN tetap bisa dibuat dan dicetak, tetapi orang tua belum bisa masuk.'),
          h('p', { className: K.teksKecil }, 'Alamat portal (tercetak di kartu):'),
          h('input', { className: K.input, readOnly: true, value: data.portal_url, onFocus: (e) => e.target.select() }))),
      h('div', { className: K.statGrid },
        kartu('Siswa Aktif', ring.total || 0),
        kartu('PIN Aktif', ring.aktif || 0),
        kartu('Belum Punya PIN', ring.belum || 0),
        kartu('Terkunci / Tidak Bisa', `${ring.terkunci || 0} / ${ring.tidak_bisa || 0}`)),
      h('div', { className: K.card },
        h('div', { className: K.cardHead },
          h('p', { className: K.judulKecil }, 'Status PIN Siswa'),
          h('div', { className: K.barKiri },
            h('button', {
              disabled: belum.length === 0,
              onClick: () => cetak('baru', []),
              className: belum.length ? K.btnHijau : K.btnMati,
            }, h(Ss, { size: 13 }), ` Buat PIN & Cetak Kartu (${belum.length} siswa belum punya PIN)`),
            h('button', {
              disabled: terpilih.length === 0,
              onClick: () => {
                if (window.confirm('PIN lama siswa terpilih tidak berlaku lagi dan perangkat yang sudah masuk akan keluar. Lanjutkan?')) cetak('reset', terpilih);
              },
              className: terpilih.length ? K.btnMerah : K.btnMati,
            }, `Reset PIN Terpilih & Cetak (${terpilih.length})`))),
        siswa.length === 0 ? h('p', { className: K.kosong }, 'Belum ada siswa aktif di kelas ini.')
          : h('div', { className: K.tabelWrap },
            h('table', { className: K.tabel },
              h('thead', { className: K.thead }, h('tr', null,
                h('th', { className: K.th }, h('input', {
                  type: 'checkbox', checked: semuaDipilih, title: 'Pilih semua',
                  onChange: (e) => { const nilai = e.target.checked; const baru = {}; siswa.filter(bisaDipilih).forEach((s) => { baru[s.student_id] = nilai; }); setPilih(baru); },
                })),
                h('th', { className: K.th }, 'Nama Siswa'),
                h('th', { className: K.th }, 'NISN'),
                h('th', { className: K.th }, 'Status PIN'),
                h('th', { className: K.th }, 'Login Terakhir'),
                h('th', { className: K.th }, 'Jumlah Login'))),
              h('tbody', { className: K.tbody }, siswa.map((s) => {
                const [label, kelasBadge] = STATUS[s.pin_status] || [s.pin_status, K.badgeAbu];
                const ket = s.pin_status === 'AKTIF' ? ` sejak ${tgl(s.pin_created_at)}` : s.pin_status === 'TERKUNCI' ? ` s.d. ${jam(s.locked_until)}` : '';
                return h('tr', { key: s.student_id, className: K.tr },
                  h('td', { className: K.td }, h('input', {
                    type: 'checkbox', disabled: !bisaDipilih(s), checked: !!pilih[s.student_id],
                    onChange: (e) => { const nilai = e.target.checked; setPilih((p) => ({ ...p, [s.student_id]: nilai })); },
                  })),
                  h('td', { className: K.tdNama }, s.student_name),
                  h('td', { className: K.td }, s.nisn || '-'),
                  h('td', { className: K.td }, h('span', { className: kelasBadge }, label), h('span', { className: K.teksKecil }, ket)),
                  h('td', { className: K.td }, s.last_login_at ? `${tgl(s.last_login_at)} ${jam(s.last_login_at)}` : '-'),
                  h('td', { className: K.td }, s.login_count || 0));
              })))),
        h('div', { className: K.cardBody },
          h('p', { className: K.teksKecil },
            'PIN hanya ditampilkan sekali di halaman kartu. Bila kartu hilang atau PIN tersebar, reset PIN siswa tersebut: PIN lama dan semua perangkat yang sudah masuk langsung tidak berlaku. '
            + 'Siswa dengan NISN kosong/ganda perlu dirapikan dulu di Data Siswa. Terkunci = 5 kali salah PIN dalam 15 menit (terbuka otomatis atau dengan reset).'))));
  }

  return h('div', { className: K.wrap }, kontrol, isi);
}

function __ponHalamanAdmin() {
  return a.jsxs('div', {
    className: __ponK.halaman,
    children: [
      a.jsx(zi, {
        title: 'Portal Nilai Orang Tua',
        description: 'Aktifkan portal, buat PIN, dan cetak kartu akses agar siswa dan orang tua dapat memantau nilai.',
        category: 'Manajemen Data',
        color: 'emerald',
        illustration: a.jsx(D_, { size: 70, className: 'text-white/80' }),
      }),
      a.jsx(__ponPanelAksesOrtu, { showClassPicker: true, isAdmin: true }),
    ],
  });
}
/* eslint-enable no-undef */

const KOMPONEN_SRC = `const __ponK=${JSON.stringify(KELAS)};${__ponPanelAksesOrtu.toString()}${__ponHalamanAdmin.toString()}`;

const patches = [
  {
    id: 'P0 komponen panel akses orang tua',
    from: 'function __pnkPantauNilaiKelas(',
    to: `${KOMPONEN_SRC}function __pnkPantauNilaiKelas(`,
  },
  {
    id: 'P1 tombol Akses Orang Tua di tab Nilai',
    from: "    h('button', { onClick: muat, className: K.btnPutih }, h(po, { size: 13 }), ' Perbarui Nilai'));",
    to: "    h('div', { className: K.barKiri },"
      + " !showClassPicker && h('button', { onClick: () => setTampilan({ jenis: 'akses' }), className: K.btnHijau }, '🔑 Akses Orang Tua'),"
      + " h('button', { onClick: muat, className: K.btnPutih }, h(po, { size: 13 }), ' Perbarui Nilai')));",
  },
  {
    id: 'P2 view akses di tab Nilai',
    from: '  } else if (memuat && !data) {',
    to: "  } else if (tampilan.jenis === 'akses') {\n"
      + "    isi = h('div', { className: K.wrap }, tombolKembali, h(__ponPanelAksesOrtu, { classId: kelas }));\n"
      + '  } else if (memuat && !data) {',
  },
  {
    id: 'P3 menu ADMIN Portal Nilai Ortu',
    from: 'roles:["ADMIN","KEPALA_SEKOLAH"]}]}],M',
    to: 'roles:["ADMIN","KEPALA_SEKOLAH"]},{id:"portal-nilai",label:"Portal Nilai Ortu",icon:a.jsx(D_,{size:20}),roles:["ADMIN"]}]}],M',
  },
  {
    id: 'P4 router halaman portal-nilai',
    from: 'e==="bk-grades"&&a.jsx(__pnkPantauNilaiKelas,{user:o,showClassPicker:!0,activeSemester:Ee}),',
    to: 'e==="bk-grades"&&a.jsx(__pnkPantauNilaiKelas,{user:o,showClassPicker:!0,activeSemester:Ee}),e==="portal-nilai"&&a.jsx(__ponHalamanAdmin,{}),',
  },
  {
    id: 'P5 daftar halaman dikenal',
    from: '"bk-incidents","bk-grades","kepegawaian-gtk"',
    to: '"bk-incidents","bk-grades","portal-nilai","kepegawaian-gtk"',
  },
];

// ---------------------------------------------------------------------------
const css = fs.readFileSync(cssPath, 'utf8');
const cssEscape = (c) => c.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
const kelas = new Set();
Object.values(KELAS).forEach((v) => v.split(/\s+/).filter(Boolean).forEach((c) => kelas.add(c)));
for (const m of (__ponPanelAksesOrtu.toString() + __ponHalamanAdmin.toString()).matchAll(/className:\s*(['"`])([^'"`]*?)\1/g)) {
  m[2].split(/\s+/).filter((c) => c && !c.includes('${')).forEach((c) => kelas.add(c));
}
const hilang = [...kelas].filter((c) => {
  const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
  return !re.test(css);
});
if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));
console.log(`OK  ${kelas.size} class Tailwind tersedia di CSS bundle`);

for (const nama of ['__ponK', '__ponPanelAksesOrtu', '__ponHalamanAdmin', '"portal-nilai"']) {
  if (src.includes(nama)) fail(`${nama} sudah ada di ${SRC_NAME}.`);
}

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
