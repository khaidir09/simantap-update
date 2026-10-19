// Patch bundle frontend: Jenjang Sekolah Dinamis (SD/SMP/SMA/SMK).
// Spesifikasi: docs/specs/jenjang-sekolah-dinamis.md — Dokumentasi patch: docs/patches/jenjang-sekolah.md
//  J0  Helper global __jj* (daftar level, parser level, opsi dropdown, contoh placeholder, modal
//      peringatan ganti jenjang), disisipkan sebelum peta class patch Dispensasi (const __dspK=).
//      Render aplikasi menunggu get_config (maks. 5 detik) agar __jjNow() sinkron di semua komponen.
//  J1–J4 Dropdown level kelas (Kelola Kelas, Tambah Penugasan Mapel, Mode Edit Parameter Guru,
//      Profil guru) dibangun dari level jenjang; nilai lama di luar jenjang tetap tampil bertanda.
//      Default level form Kelola Kelas = level pertama jenjang.
//  J5  Fallback level ["X","XI","XII"] di penugasan mapel → level jenjang.
//  J6  Form jurnal: level kelas lewat parser (bukan startsWith X/XI/XII).
//  J7  Placeholder/contoh yang menyebut level kelas mengikuti jenjang.
//  J8  Menu "Bimbingan PKL" hanya untuk jenjang SMK.
//  J9  Router: halaman bimbingan-pkl di luar SMK dialihkan ke dashboard.
//  J10 Pengaturan (tab konfigurasi): pilihan Jenjang Sekolah + modal peringatan saat 409.
// Prasyarat: patch Bimbingan PKL (apply-bimbingan-pkl-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-jenjang-sekolah-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
// Dapat di-require (uji parser): module.exports.SNIPPET_PARSER tanpa menjalankan patch.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-pkl2.js';
const OUT_NAME = 'index-CGWL69P7-jenjang1.js';
const OUT_LAMA = [];
const SRC_SHA256 = '65f184be84a2086aed68d06f227657e6996cf0e586a28153280f89d7c770ddb8';
const CSS_NAME = 'index-CC1PBzaO.css';

// ---------------------------------------------------------------------------
// Class Tailwind (divalidasi terhadap CSS bundle).
// ---------------------------------------------------------------------------
const KELAS = {
  overlay: 'fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm',
  kotak: 'bg-white rounded-2xl w-full max-w-md shadow-2xl overflow-hidden',
  kepala: 'p-6 border-b border-slate-100',
  judul: 'text-xl font-bold',
  isi: 'p-6 space-y-4',
  pesan: 'p-4 rounded-2xl bg-amber-50 text-amber-700 text-sm font-bold leading-relaxed',
  teks: 'text-sm text-slate-500',
  tombolWrap: 'flex justify-end gap-2',
  batal: 'px-4 py-2 rounded-xl border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50',
  lanjut: 'px-4 py-2 rounded-xl bg-amber-600 text-white text-sm font-bold hover:bg-amber-700',
};
// Class yang ditulis langsung di teks patch J10 (bukan lewat __jjK)
const KELAS_PATCH = ['text-[10px]', 'font-bold', 'text-slate-400'];

// ---------------------------------------------------------------------------
// J0 Helper. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas dari scope modul bundle: a = jsx runtime, ue = React, nr = URL api/index.php.
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */

// Aturan harus identik dengan jenjangParseLevel() di api/jenjang_sekolah.php.
function __jjParseLevel(teks, levels) {
  let t = String(teks == null ? '' : teks).trim().toUpperCase().replace(/^KELAS\s+/, '');
  if (!t) return '';
  const m = t.match(/^(1[0-2]|[1-9])(?![0-9])/);
  if (m) return __jjAngka[Number(m[1])];
  for (const r of __jjRomawi) {
    if (!t.startsWith(r)) continue;
    const s = t.charAt(r.length);
    if (s === '' || /[\s_\-./]/.test(s)) return r;
  }
  const L = levels || __jjNow().levels;
  for (const r of __jjRomawi) {
    if (!t.startsWith(r)) continue;
    const s = t.charAt(r.length);
    if (/[A-Z]/.test(s) && !/[IVX]/.test(s) && L.includes(r)) return r;
  }
  return '';
}

function __jjNow() {
  return __jjState;
}

function __jjSet(jenjang, sumber) {
  const j = String(jenjang || '').trim().toUpperCase();
  if (!__jjDaftar[j]) return;
  __jjState = { jenjang: j, sumber: sumber || 'pengaturan', levels: __jjDaftar[j].slice(), pklAktif: j === 'SMK' };
}

// Dipanggil sekali sebelum render aplikasi. Gagal/timeout → bawaan SMA tanpa PKL (backend tetap penentu akses).
function __jjMuat() {
  const muat = fetch(nr + '?action=get_config')
    .then((r) => (r.ok ? r.json() : null))
    .then((c) => {
      if (c && c.school_level) __jjSet(c.school_level, c.school_level_sumber);
    });
  return Promise.race([muat, new Promise((r) => setTimeout(r, 5000))]).catch((e) => console.error(e));
}

// Opsi <option> level jenjang aktif; nilai lama di luar jenjang tetap ditampilkan agar tidak hilang diam-diam.
function __jjOpsiLevel(nilai) {
  const L = __jjNow().levels;
  const opsi = L.map((l) => a.jsx('option', { value: l, children: 'Kelas ' + l }, 'jj-' + l));
  const v = String(nilai == null ? '' : nilai).trim();
  if (v && !L.some((l) => l.toLowerCase() === v.toLowerCase())) {
    opsi.push(a.jsx('option', { value: v, children: 'Kelas ' + v + ' (di luar jenjang)' }, 'jj-luar'));
  }
  return opsi;
}

function __jjContoh(jenis) {
  const { jenjang, levels: L } = __jjNow();
  const asli = {
    namaKelas: 'Contoh: X IPA 1',
    kelasTingkat: 'Contoh: Kelas X (Sepuluh)',
    sesiTingkat: 'X / XI / XII (Fase E/F)',
    rpp: 'Contoh: RPP Matematika Trigonometri Kelas X',
    silabus: 'Contoh: Silabus Matematika Fase E Kelas X',
    buku: 'Buku Siswa Matematika Kelas XI, Lembar Desain Proyek',
  };
  if (jenjang === 'SMK' && jenis === 'namaKelas') return 'Contoh: X TKJ 1';
  if (jenjang === 'SMA' || jenjang === 'SMK') return asli[jenis] || '';
  const c = jenjang === 'SD' ? 'IV' : 'VII';
  const sebut = { IV: 'Empat', VII: 'Tujuh' };
  switch (jenis) {
    case 'namaKelas': return `Contoh: ${c} A`;
    case 'kelasTingkat': return `Contoh: Kelas ${c} (${sebut[c]})`;
    case 'sesiTingkat': return L.join(' / ');
    case 'rpp': return `Contoh: RPP Matematika Kelas ${L[0]}`;
    case 'silabus': return `Contoh: Silabus Matematika Kelas ${L[0]}`;
    case 'buku': return `Buku Siswa Matematika Kelas ${L[1] || L[0]}, Lembar Desain Proyek`;
    default: return asli[jenis] || '';
  }
}

// Halaman PKL di luar SMK: alihkan ke dashboard (setelah render, bukan saat render).
function __jjAlihkan({ ke }) {
  ue.useEffect(() => { ke(); }, []);
  return null;
}

function __jjModalJenjang({ data, onBatal, onLanjut }) {
  const d = data || {};
  const ke = d.ke || '';
  const pesan = `Mengubah jenjang dari ${d.dari || '-'} ke ${ke}. Ada ${d.kelas || 0} kelas, ${d.penugasan || 0} penugasan mapel, dan ${d.siswa || 0} siswa aktif dengan level di luar jenjang ${ke}.`;
  return a.jsx('div', {
    className: __jjK.overlay,
    children: a.jsxs('div', {
      className: __jjK.kotak,
      children: [
        a.jsx('div', { className: __jjK.kepala, children: a.jsx('h2', { className: __jjK.judul, children: 'Ganti Jenjang Sekolah?' }) }),
        a.jsxs('div', {
          className: __jjK.isi,
          children: [
            a.jsx('p', { className: __jjK.pesan, children: pesan }),
            a.jsx('p', { className: __jjK.teks, children: 'Data tersebut tidak diubah, tetapi akan ditandai "di luar jenjang" sampai diperbaiki. Lanjutkan?' }),
            a.jsxs('div', {
              className: __jjK.tombolWrap,
              children: [
                a.jsx('button', { type: 'button', onClick: onBatal, className: __jjK.batal, children: 'Batal' }),
                a.jsx('button', { type: 'button', onClick: onLanjut, className: __jjK.lanjut, children: 'Lanjutkan' }),
              ],
            }),
          ],
        }),
      ],
    }),
  });
}

/* eslint-enable no-undef */

const FUNGSI = [__jjParseLevel, __jjNow, __jjSet, __jjMuat, __jjOpsiLevel, __jjContoh, __jjAlihkan, __jjModalJenjang];

const SNIPPET_DATA =
  'const __jjDaftar={SD:["I","II","III","IV","V","VI"],SMP:["VII","VIII","IX"],SMA:["X","XI","XII"],SMK:["X","XI","XII"]};' +
  'const __jjRomawi=["XII","VIII","XI","VII","III","IX","IV","VI","II","X","V","I"];' +
  'const __jjAngka=["","I","II","III","IV","V","VI","VII","VIII","IX","X","XI","XII"];' +
  'let __jjState={jenjang:"SMA",sumber:"bawaan",levels:["X","XI","XII"],pklAktif:!1};';

// Bagian yang diuji paritasnya dengan PHP (docs/patches/uji-jenjang-sekolah-parser.mjs)
const SNIPPET_PARSER = SNIPPET_DATA + __jjNow.toString() + __jjSet.toString() + __jjParseLevel.toString();

module.exports = { SNIPPET_PARSER };

// ---------------------------------------------------------------------------
// Penggantian string
// ---------------------------------------------------------------------------
const OPSI_LAMA = 'a.jsx("option",{value:"X",children:"Kelas X"}),a.jsx("option",{value:"XI",children:"Kelas XI"}),a.jsx("option",{value:"XII",children:"Kelas XII"})';

const SAVE_LAMA = 'const Ae=async()=>{try{t(!0),(await fetch(nr+"?action=save_system_config",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({configs:ie})})).ok?(GTe(),alert("Konfigurasi Kop Surat berhasil disimpan!")):alert("Gagal menyimpan konfigurasi.")}catch(Se){console.error(Se),alert("Terjadi kesalahan.")}finally{t(!1)}}';
// Argumen pertama: true = kirim ulang dengan konfirmasi (onClick mengirim event, bukan true).
const SAVE_BARU = 'const Ae=async(__jjKonf)=>{try{t(!0);const __jjR=await fetch(nr+"?action=save_system_config",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({configs:ie,konfirmasi_jenjang:__jjKonf===!0})});let __jjD=null;try{__jjD=await __jjR.json()}catch{}if(__jjR.status===409&&__jjD&&__jjD.peringatan_jenjang){__jjSetWarn(__jjD.peringatan_jenjang);return}if(__jjR.ok){GTe();const __jjGanti=!!ie.school_level&&String(ie.school_level).toUpperCase()!==__jjNow().jenjang;alert("Konfigurasi Kop Surat berhasil disimpan!");__jjGanti&&window.location.reload()}else alert("Gagal menyimpan konfigurasi."+(__jjD&&__jjD.error?" "+__jjD.error:""))}catch(Se){console.error(Se),alert("Terjadi kesalahan.")}finally{t(!1)}}';

const FIELD_JENJANG =
  ',a.jsxs("div",{className:"space-y-1.5",children:[a.jsxs("label",{className:"text-[10px] font-black text-slate-500 uppercase tracking-widest flex items-center gap-1.5",children:[a.jsx(H2,{size:12})," Jenjang Sekolah"]}),' +
  'a.jsxs("select",{value:String(ie.school_level||__jjNow().jenjang).toUpperCase(),onChange:Se=>le({...ie,school_level:Se.target.value}),className:"w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold focus:ring-2 focus:ring-blue-500 outline-none cursor-pointer",children:[' +
  'a.jsx("option",{value:"SD",children:"SD (Kelas I–VI)"}),a.jsx("option",{value:"SMP",children:"SMP (Kelas VII–IX)"}),a.jsx("option",{value:"SMA",children:"SMA (Kelas X–XII)"}),a.jsx("option",{value:"SMK",children:"SMK (Kelas X–XII, Bimbingan PKL)"})]}),' +
  '__jjNow().sumber==="deteksi"&&a.jsx("p",{className:"text-[10px] font-bold text-slate-400",children:"Terdeteksi otomatis dari nama sekolah, simpan untuk menetapkan."}),' +
  '__jjWarn&&a.jsx(__jjModalJenjang,{data:__jjWarn,onBatal:()=>__jjSetWarn(null),onLanjut:()=>{__jjSetWarn(null),Ae(!0)}})]})';

const PLACEHOLDER = [
  ['namaKelas', 'Contoh: X IPA 1'],
  ['kelasTingkat', 'Contoh: Kelas X (Sepuluh)'],
  ['sesiTingkat', 'X / XI / XII (Fase E/F)'],
  ['rpp', 'Contoh: RPP Matematika Trigonometri Kelas X'],
  ['silabus', 'Contoh: Silabus Matematika Fase E Kelas X'],
  ['buku', 'Buku Siswa Matematika Kelas XI, Lembar Desain Proyek'],
];

const ROOT_RENDER = 'poe.createRoot(document.getElementById("root")).render(a.jsx(B.StrictMode,{children:a.jsx(kot,{})}));';

function buatPatches() {
  const kode = SNIPPET_DATA + 'const __jjK=' + JSON.stringify(KELAS) + ';' + FUNGSI.map((f) => f.toString()).join('\n');
  return [
    { id: 'J0 helper __jj*', from: 'const __dspK=', to: kode + '\nconst __dspK=' },
    { id: 'J0 render menunggu jenjang', from: ROOT_RENDER, to: '__jjMuat().finally(()=>{' + ROOT_RENDER + '});' },
    // J1–J4: urutan kemunculan di bundle → ekspresi value <select>-nya
    { id: 'J1–J4 dropdown level', from: OPSI_LAMA, count: 4, ganti: dropdownLevel },
    { id: 'J1 default level Kelola Kelas', from: 'level:"X",homeroom_teacher:""', count: 2, to: 'level:__jjNow().levels[0],homeroom_teacher:""' },
    { id: 'J5 fallback level penugasan', from: ':["X","XI","XII"]})();const __tmClsOf=', to: ':__jjNow().levels.slice()})();const __tmClsOf=' },
    {
      id: 'J6 parser level form jurnal',
      from: 'const jn=un.id.startsWith("XII")?"XII":un.id.startsWith("XI")?"XI":un.id.startsWith("X")?"X":"";',
      to: 'const jn=__jjParseLevel(un.id)||__jjParseLevel(un.name);',
    },
    ...PLACEHOLDER.map(([jenis, teks]) => ({
      id: 'J7 placeholder ' + jenis,
      from: 'placeholder:' + JSON.stringify(teks),
      to: 'placeholder:__jjContoh(' + JSON.stringify(jenis) + ')',
    })),
    {
      id: 'J8 menu PKL khusus SMK',
      from: '{id:"bimbingan-pkl",label:"Bimbingan PKL",icon:a.jsx(dW,{size:20}),roles:["GURU","KEPALA_SEKOLAH","ADMIN"]}',
      to: '{id:"bimbingan-pkl",label:"Bimbingan PKL",icon:a.jsx(dW,{size:20}),roles:__jjNow().pklAktif?["GURU","KEPALA_SEKOLAH","ADMIN"]:[]}',
    },
    {
      id: 'J9 router PKL khusus SMK',
      from: 'e==="bimbingan-pkl"&&a.jsx(__pklHalaman,{}),',
      to: 'e==="bimbingan-pkl"&&(__jjNow().pklAktif?a.jsx(__pklHalaman,{}):a.jsx(__jjAlihkan,{ke:()=>t("dashboard")})),',
    },
    {
      id: 'J10 state modal jenjang',
      from: 'catch(console.error)},[]);const[ie,le]=ue.useState({kop_instansi:',
      to: 'catch(console.error)},[]);const[__jjWarn,__jjSetWarn]=ue.useState(null);const[ie,le]=ue.useState({kop_instansi:',
    },
    { id: 'J10 simpan konfigurasi + 409', from: SAVE_LAMA, to: SAVE_BARU },
    {
      id: 'J10 field Jenjang Sekolah',
      from: 'Semester Genap"})]})]})]}),a.jsx("div",{className:"pt-4 border-t border-slate-200",children:a.jsx("button",{onClick:Ae',
      to: 'Semester Genap"})]})]})' + FIELD_JENJANG + ']}),a.jsx("div",{className:"pt-4 border-t border-slate-200",children:a.jsx("button",{onClick:Ae',
    },
  ];
}

// Ganti 4 dropdown level; tiap kemunculan dicek ekspresi value <select> pemiliknya.
const VALUE_DROPDOWN = ['u.level', 'ne', 'be', 'qe'];
function dropdownLevel(teks) {
  const bagian = teks.split(OPSI_LAMA);
  let out = bagian[0];
  for (let i = 1; i < bagian.length; i++) {
    const sebelum = out.slice(-1500);
    const m = [...sebelum.matchAll(/a\.jsxs\("select",\{(?:required:!0,)?value:([^,]+),onChange:/g)].pop();
    const v = m && m[1];
    if (v !== VALUE_DROPDOWN[i - 1]) fail(`J1–J4: dropdown ke-${i} memakai value "${v}", diharapkan "${VALUE_DROPDOWN[i - 1]}".`);
    out += `...__jjOpsiLevel(${v})` + bagian[i];
  }
  return out;
}

function fail(msg) {
  console.error('GAGAL: ' + msg);
  process.exit(1);
}

function main() {
  const srcPath = path.join(ROOT, 'assets', SRC_NAME);
  const outPath = path.join(ROOT, 'assets', OUT_NAME);
  const cssPath = path.join(ROOT, 'assets', CSS_NAME);
  const appHtmlPath = path.join(ROOT, 'app.html');

  const src = fs.readFileSync(srcPath, 'utf8');
  const hash = crypto.createHash('sha256').update(fs.readFileSync(srcPath)).digest('hex');
  if (hash !== SRC_SHA256) fail(`hash ${SRC_NAME} berbeda (${hash}). Bundle sudah berubah, cari ulang anchor secara manual.`);

  const css = fs.readFileSync(cssPath, 'utf8');
  const cssEscape = (c) => c.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
  const kelas = new Set(KELAS_PATCH);
  Object.values(KELAS).forEach((v) => v.split(/\s+/).filter(Boolean).forEach((c) => kelas.add(c)));
  const hilang = [...kelas].filter((c) => {
    const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
    return !re.test(css);
  });
  if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));
  console.log(`OK  ${kelas.size} class Tailwind tersedia di CSS bundle`);

  for (const nama of ['__jjK', '__jjNow', '__jjParseLevel']) {
    if (src.includes(nama)) fail(`${nama} sudah ada di ${SRC_NAME}.`);
  }
  // Identifier bundle yang dipakai patch harus ada
  if (!/[,;\s]nr=`\$\{L_\}\/api\/index\.php`/.test(src)) fail(`nr (URL API) tidak ditemukan di ${SRC_NAME}.`);
  if (!/[,;\s]H2=Nt\("/.test(src)) fail(`ikon H2 tidak ditemukan di ${SRC_NAME}.`);
  if (src.split('onNavigate:be=>t(be)').length - 1 !== 1) fail('setter halaman t (onNavigate:be=>t(be)) tidak ditemukan tepat 1 kali.');

  let out = src;
  for (const p of buatPatches()) {
    const harus = p.count || 1;
    const count = out.split(p.from).length - 1;
    if (count !== harus) fail(`${p.id}: anchor ditemukan ${count} kali (harus ${harus}).`);
    out = p.ganti ? p.ganti(out) : out.split(p.from).join(p.to);
    console.log('OK  ' + p.id);
  }

  // Tidak boleh ada sisa hardcode level di luar helper
  const sisa = out.replace(SNIPPET_DATA, '');
  for (const s of [OPSI_LAMA, '["X","XI","XII"]', 'startsWith("XII")']) {
    if (sisa.includes(s)) fail(`masih ada sisa ${s} setelah patch.`);
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
}

if (require.main === module) main();
