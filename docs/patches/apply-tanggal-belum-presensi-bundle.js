// Patch bundle frontend: Dashboard Kelas (wali kelas) menampilkan tanggal yang belum ada presensi.
// Dokumentasi patch: docs/patches/tanggal-belum-presensi.md
//  T0 Helper global __tbpFmt (format tanggal Indonesia pendek: "Sen, 3 Nov").
//  T1 State presensi_kosong di komponen dot (Dashboard Kelas).
//  T2 Isi state dari respons get_homeroom_dashboard.
//  T3 Panel "Tanggal Belum Ada Presensi" di bawah kartu statistik, di atas tab.
// Prasyarat: patch Verifikasi Otomatis (apply-verifikasi-otomatis-bundle.js) sudah diterapkan,
// dan backend api/presensi_kosong.php sudah terpasang (field presensi_kosong di get_homeroom_dashboard).
// Pemakaian (dari root deploy): node docs/patches/apply-tanggal-belum-presensi-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-verifotomatis1.js';
const OUT_NAME = 'index-CGWL69P7-tglpresensi1.js';
const SRC_SHA256 = 'c3deb03c1a102e87ee6019d01c3b175c315a4f682e727de6cb9b7e11e80e51b4';
const CSS_NAME = 'index-CC1PBzaO.css';

// ---------------------------------------------------------------------------
// T0 Helper format tanggal (tanggal Y-m-d dibaca sebagai waktu lokal, bukan UTC)
// ---------------------------------------------------------------------------
const SNIPPET_HELPER =
  'function __tbpFmt(d){try{return new Date(d+"T00:00:00").toLocaleDateString("id-ID",{weekday:"short",day:"numeric",month:"short"})}catch{return d}}\n';

// ---------------------------------------------------------------------------
// T3 Panel. Variabel dalam dot: _tbp = presensi_kosong, cr = ikon circle-check, Qu = ikon circle-alert.
// ---------------------------------------------------------------------------
const PANEL =
  '_tbp&&_tbp.mulai&&((_n,_lb)=>a.jsxs("div",{className:$e("rounded-3xl border p-5 space-y-3",_n>0?"bg-amber-50 border-amber-100":"bg-emerald-50 border-emerald-100"),children:[' +
    'a.jsxs("div",{className:"flex items-center gap-2",children:[' +
      '_n>0?a.jsx(Qu,{size:16,className:"text-amber-600"}):a.jsx(cr,{size:16,className:"text-emerald-600"}),' +
      'a.jsx("p",{className:$e("text-xs font-black uppercase tracking-widest",_n>0?"text-amber-700":"text-emerald-700"),children:_n>0?`${_n} Tanggal Belum Ada Presensi`:"Presensi Kelas Lengkap"})]}),' +
    'a.jsx("p",{className:"text-xs font-bold text-slate-500",children:`Dicek ${Number(_tbp.hari_sekolah)===5?"Senin–Jumat (sekolah 5 hari)":"Senin–Sabtu (sekolah 6 hari)"}, ${__tbpFmt(_tbp.mulai)} s.d. ${__tbpFmt(_tbp.sampai)}. Presensi terisi otomatis dari jurnal mengajar guru atau dari Presensi Kelas (manual).`}),' +
    '_n>0&&a.jsx("div",{className:"flex flex-wrap gap-2 max-h-32 overflow-y-auto",children:_tbp.tanggal.map(_d=>a.jsx("span",{className:"px-3 py-1 bg-white border border-amber-200 rounded-xl text-xs font-black text-amber-700",children:__tbpFmt(_d)+(_d===_tbp.hari_ini?" (hari ini)":"")},_d))}),' +
    '_lb.length>0&&a.jsx("p",{className:"text-[10px] font-bold text-slate-400",children:`Tidak dihitung karena tidak ada presensi di seluruh kelas (kemungkinan libur): ${_lb.map(__tbpFmt).join(", ")}.`})' +
  ']}))(Array.isArray(_tbp.tanggal)?_tbp.tanggal.length:0,Array.isArray(_tbp.kemungkinan_libur)?_tbp.kemungkinan_libur:[]),';

const KELAS_PATCH = (PANEL.match(/"[a-z][^"]*"/g) || [])
  .map((m) => m.slice(1, -1))
  .filter((m) => /^[a-z0-9:.\[\]\/-]+( [a-z0-9:.\[\]\/-]+)*$/.test(m) && /[-]/.test(m))
  .flatMap((m) => m.split(' '))
  .filter((c) => !['id-ID'].includes(c));

const TABS_ANCHOR = 'a.jsx("div",{className:"flex bg-slate-100 p-1.5 rounded-2xl w-fit",children:[{id:"overview"';

const patches = [
  { id: 'T0 helper __tbpFmt', from: 'function dot({user:e,', to: SNIPPET_HELPER + 'function dot({user:e,' },
  {
    id: 'T1 state presensi_kosong',
    from: '_rn=(e==null?void 0:e.class_homeroom_name)||r,',
    to: '_rn=(e==null?void 0:e.class_homeroom_name)||r,[_tbp,_tbpSet]=ue.useState(null),',
  },
  { id: 'T2 isi state dari get_homeroom_dashboard', from: 'i(ne),l(K)}if(H.ok)', to: 'i(ne),l(K),_tbpSet(Y.presensi_kosong||null)}if(H.ok)' },
  { id: 'T3 panel tanggal belum presensi', from: TABS_ANCHOR, to: PANEL + TABS_ANCHOR },
];

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
  const hilang = [...kelas].filter((c) => {
    const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
    return !re.test(css);
  });
  if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));
  console.log(`OK  ${kelas.size} class Tailwind tersedia di CSS bundle`);

  if (src.includes('__tbp') || src.includes('_tbp')) fail(`identifier _tbp/__tbp sudah ada di ${SRC_NAME}.`);
  // Ikon yang dipakai panel harus top-level dan tidak dideklarasikan ulang di dalam dot
  if (!/[,;\s]cr=Nt\("circle-check"/.test(src)) fail('ikon cr (circle-check) tidak ditemukan.');
  if (!/[,;\s]Qu=Nt\("circle-alert"/.test(src)) fail('ikon Qu (circle-alert) tidak ditemukan.');
  const iDot = src.indexOf('function dot({user:e,');
  const iTabs = src.indexOf(TABS_ANCHOR, iDot);
  if (iDot < 0 || iTabs < 0) fail('komponen dot atau anchor tab tidak ditemukan.');
  if (/[,{(\s](cr|Qu|\$e)=/.test(src.slice(iDot, iTabs))) fail('cr/Qu/$e dideklarasikan ulang di dalam dot.');

  let out = src;
  for (const p of patches) {
    const count = out.split(p.from).length - 1;
    if (count !== 1) fail(`${p.id}: anchor ditemukan ${count} kali (harus 1).`);
    out = out.split(p.from).join(p.to);
    console.log('OK  ' + p.id);
  }

  fs.writeFileSync(outPath, out, 'utf8');
  console.log(`Ditulis assets/${OUT_NAME}`);

  const html = fs.readFileSync(appHtmlPath, 'utf8');
  if (html.includes(`./assets/${OUT_NAME}`)) {
    console.log('app.html sudah mereferensikan bundle patch.');
  } else {
    const ref = `./assets/${SRC_NAME}`;
    if (html.split(ref).length - 1 !== 1) fail(`app.html tidak mereferensikan ${SRC_NAME} tepat 1 kali.`);
    fs.writeFileSync(appHtmlPath, html.replace(ref, `./assets/${OUT_NAME}`), 'utf8');
    console.log(`app.html diarahkan ke assets/${OUT_NAME}`);
  }
}

if (require.main === module) main();
