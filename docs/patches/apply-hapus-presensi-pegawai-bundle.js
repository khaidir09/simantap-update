// Patch bundle frontend: hapus menu "Presensi Pegawai" (staff-attendance) untuk semua role.
//  P1 Sidebar: hapus grup "Kepegawaian" (satu-satunya item: Presensi Pegawai).
//  P2 Konten: hapus render halaman staff-attendance.
//  P3 Daftar tab dikenal: hapus "staff-attendance".
// Endpoint backend presensi pegawai (endpoints_tu.php) dan datanya TIDAK diubah.
// Prasyarat: patch persetujuan jurnal (apply-persetujuan-jurnal-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-hapus-presensi-pegawai-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-setuju1.js';
const OUT_NAME = 'index-CGWL69P7-tanpapresensi1.js';
const SRC_SHA256 = '171849275eb1afeeb217cbfb09d44578b9217951253aa9abfd9adf684b108ad5';

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

const patches = [
  {
    id: 'P1 hapus grup menu Kepegawaian',
    from: '{title:"Kepegawaian",items:[{id:"staff-attendance",label:"Presensi Pegawai",icon:a.jsxs("svg",{viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:"2.5",strokeLinecap:"round",strokeLinejoin:"round",className:"w-5 h-5",children:[a.jsx("path",{d:"M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"}),a.jsx("circle",{cx:"12",cy:"7",r:"4"})]}),roles:["GURU","KEPALA_SEKOLAH","TATA_USAHA"]}]},',
    to: '',
  },
  {
    id: 'P2 hapus render halaman staff-attendance',
    from: 'e==="staff-attendance"&&a.jsx(Xat,{user:o}),',
    to: '',
  },
  {
    id: 'P3 hapus staff-attendance dari daftar tab',
    from: '"student-late","staff-attendance","lesson-kit"',
    to: '"student-late","lesson-kit"',
  },
];

let out = src;
for (const p of patches) {
  const count = out.split(p.from).length - 1;
  if (count !== 1) fail(`${p.id}: anchor ditemukan ${count} kali (harus 1).`);
  out = out.replace(p.from, () => p.to);
  console.log('OK  ' + p.id);
}
if (out.includes('staff-attendance')) fail('masih ada rujukan "staff-attendance" di hasil patch.');

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
