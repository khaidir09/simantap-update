// Patch bundle frontend: tampilkan Media / Alat Pembelajaran di detail dan cetak jurnal.
// Dokumentasi: docs/patches/label-media-jurnal.md (bagian 7)
//  S1 Normalisasi daftar jurnal: field alatMedia ikut dibawa (sebelumnya dibuang).
//  S2 Modal detail jurnal, "Topik & Aktivitas Pembelajaran": kartu "Media / Alat Pembelajaran"
//     di samping kartu Metode; kartu Kendala dilebarkan penuh (inline style, karena class
//     sm:col-span-2 tidak ada di CSS bundle).
//  S3 Cetak detail jurnal: "3. Metode Pembelajaran" menjadi "3. Metode / Media Ajar", isi
//     "Project Based Learning / LCD, Laptop, Modul".
// Prasyarat: patch Label Media (apply-label-media-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-media-detail-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-labelmedia1.js';
const OUT_NAME = 'index-CGWL69P7-mediadetail1.js';
const SRC_SHA256 = 'eb3b9df3e945f02f481dcac438898514ba1bcc7533cb96920e22ac0dc4ffb125';

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

// Nilai media dari objek jurnal (hasil normalisasi atau data mentah API).
const MEDIA = '(e.alatMedia||e.alat_media||"")';
// Semua class di bawah disalin dari kartu/isian yang sudah ada di bundle yang sama.
const KARTU = 'p-3 bg-white rounded-2xl border border-slate-100 shadow-sm';

const patches = [
  {
    id: 'S1 normalisasi jurnal membawa alatMedia',
    from: 'metode:ce.metode||ce.method||"",kendala:',
    to: 'metode:ce.metode||ce.method||"",alatMedia:ce.alatMedia||ce.alat_media||"",kendala:',
  },
  {
    id: 'S2 kartu media di modal detail jurnal',
    from: `children:"Metode Pembelajaran"}),a.jsx("p",{className:"text-xs font-bold text-slate-600 mt-1",children:e.metode||"Ceramah & Diskusi"})]}),a.jsxs("div",{className:"${KARTU}",children:[a.jsx("p",{className:"text-[9px] font-black text-slate-300 uppercase",children:"Kendala / Masalah"})`,
    to: `children:"Metode Pembelajaran"}),a.jsx("p",{className:"text-xs font-bold text-slate-600 mt-1",children:e.metode||"Ceramah & Diskusi"})]}),`
      + `a.jsxs("div",{className:"${KARTU}",children:[a.jsx("p",{className:"text-[9px] font-black text-slate-300 uppercase",children:"Media / Alat Pembelajaran"}),`
      + `a.jsx("p",{className:"text-xs font-bold text-slate-600 mt-1",children:${MEDIA}||"-"})]}),`
      + `a.jsxs("div",{className:"${KARTU}",style:{gridColumn:"1 / -1"},children:[a.jsx("p",{className:"text-[9px] font-black text-slate-300 uppercase",children:"Kendala / Masalah"})`,
  },
  {
    id: 'S3 cetak: 3. Metode / Media Ajar',
    from: 'children:"3. Metode Pembelajaran:"}),a.jsx("div",{className:"p-2.5 bg-slate-50 rounded border border-slate-200 font-semibold text-slate-700",children:e.metode||"Ceramah, Diskusi Kelas & Tanya Jawab"})',
    to: 'children:"3. Metode / Media Ajar:"}),a.jsx("div",{className:"p-2.5 bg-slate-50 rounded border border-slate-200 font-semibold text-slate-700",'
      + `children:[e.metode||"Ceramah, Diskusi Kelas & Tanya Jawab",${MEDIA}.trim()].filter(Boolean).join(" / ")})`,
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
