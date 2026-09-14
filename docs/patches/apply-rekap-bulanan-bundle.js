// Patch bundle frontend: tombol "Cetak Rekap Bulanan" di Dashboard Kelas Saya.
// Spesifikasi: docs/specs/rekap-bulanan-wali-kelas.md
// Prasyarat: patch wali kelas (apply-wali-kelas-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-rekap-bulanan-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-wali1.js';
const OUT_NAME = 'index-CGWL69P7-wali2.js';
const SRC_SHA256 = '8e153c7fd69ee8cf789e0f7c8be89f2b06d564f8b4e3b8b799e71eecb01f0d97';

const srcPath = path.join(ROOT, 'assets', SRC_NAME);
const outPath = path.join(ROOT, 'assets', OUT_NAME);
const appHtmlPath = path.join(ROOT, 'app.html');

function fail(msg) {
  console.error('GAGAL: ' + msg);
  process.exit(1);
}

const src = fs.readFileSync(srcPath, 'utf8');
const hash = crypto.createHash('sha256').update(fs.readFileSync(srcPath)).digest('hex');
if (hash !== SRC_SHA256) fail(`hash ${SRC_NAME} berbeda (${hash}). Bundle sudah berubah, cari ulang anchor secara manual.`);

// Tombol "Perbarui Data" di komponen dot (Dashboard Kelas Saya). r = class_homeroom, K$ = URL API, Ss = ikon printer.
const REFRESH_BUTTON = 'a.jsxs("button",{onClick:L,className:"flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-500 hover:bg-slate-50 transition-all",children:[a.jsx(po,{size:13})," Perbarui Data"]})';

// Bulan default: tanggal 1–10 bulan lalu, selain itu bulan berjalan. auto=1 agar backend menggeser
// bulan yang jatuh di luar tahun ajaran aktif (mis. Juni saat awal Juli).
const OPEN_RECAP = '()=>{const _d=new Date(),_m=_d.getDate()<=10?new Date(_d.getFullYear(),_d.getMonth()-1,1):_d,'
  + '_ym=_m.getFullYear()+"-"+String(_m.getMonth()+1).padStart(2,"0");'
  + 'window.open(`${K$}?action=cetak_rekap_bulanan&class_id=${encodeURIComponent(r)}&month=${_ym}&auto=1`,"_blank")}';

const PRINT_BUTTON = `r&&a.jsxs("button",{onClick:${OPEN_RECAP},className:"flex items-center gap-2 px-4 py-2 bg-emerald-600 border border-emerald-600 rounded-xl text-xs font-black text-white hover:bg-emerald-700 transition-all",children:[a.jsx(Ss,{size:13})," Cetak Rekap Bulanan"]})`;

const patches = [
  {
    id: 'R1 Dashboard Kelas: tombol Cetak Rekap Bulanan di samping Perbarui Data',
    from: REFRESH_BUTTON,
    to: `a.jsxs("div",{className:"flex flex-wrap items-center gap-2",children:[${REFRESH_BUTTON},${PRINT_BUTTON}]})`,
  },
];

let out = src;
for (const p of patches) {
  const count = out.split(p.from).length - 1;
  if (count !== 1) fail(`${p.id}: anchor ditemukan ${count} kali (harus 1).`);
  out = out.replace(p.from, () => p.to);
  console.log('OK  ' + p.id);
}

fs.writeFileSync(outPath, out, 'utf8');
console.log(`Ditulis: assets/${OUT_NAME}`);
console.log('SHA-256 hasil: ' + crypto.createHash('sha256').update(fs.readFileSync(outPath)).digest('hex'));

const html = fs.readFileSync(appHtmlPath, 'utf8');
if (html.includes(`./assets/${OUT_NAME}`)) {
  console.log('app.html sudah mereferensikan bundle patch.');
} else {
  const ref = `./assets/${SRC_NAME}`;
  if (html.split(ref).length - 1 !== 1) fail(`app.html tidak mereferensikan ${ref} tepat 1 kali.`);
  fs.writeFileSync(appHtmlPath, html.replace(ref, `./assets/${OUT_NAME}`), 'utf8');
  console.log(`app.html diarahkan ke assets/${OUT_NAME}`);
}
