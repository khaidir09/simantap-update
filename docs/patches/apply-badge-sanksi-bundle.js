// Patch bundle frontend: badge "Sanksi: X Poin" di kolom nama halaman Data Pelanggaran.
//  B1 Badge dihitung per siswa (studentId, fallback nama) dan hanya dari insiden VIOLATION/LATE,
//     sama seperti "Poin Sanksi" di rekap siswa. Sebelumnya dijumlahkan per nama siswa dari semua
//     data yang dimuat, sehingga dua siswa bernama sama digabung dan di tab Panggilan Ortu yang
//     dijumlahkan adalah total_points panggilan.
//  B2 Label badge menjadi "Total Sanksi: X Poin" agar tidak tertukar dengan poin per baris
//     di kolom Pelanggaran (mis. "+5 poin" untuk satu kali ALFA).
//  B3 Rekap siswa (tabel rekap, grafik, cetak laporan siswa) dikelompokkan per studentId,
//     fallback nama bila studentId kosong.
//  B4 Warna badge merah mulai 20 poin (sebelumnya > 20), sama dengan batas Tingkat III
//     di kolom Kategori Sanksi.
// Pemakaian (dari root deploy): node docs/patches/apply-badge-sanksi-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-tahunajaran2.js';
const OUT_NAME = 'index-CGWL69P7-badgesanksi.js';
const SRC_SHA256 = '80400e026159e88ce12fd3b7d8a86f73275b93930cce9e2f4bb3c009ffae226b';

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
    id: 'B1 badge: jumlah poin per studentId, hanya VIOLATION/LATE',
    count: 1,
    from: 'it=ue.useMemo(()=>o.reduce((ze,Se)=>(Se.points&&(ze[Se.studentName]=(ze[Se.studentName]||0)+Se.points),ze),{}),[o])',
    to: 'it=ue.useMemo(()=>o.reduce((ze,Se)=>((Se.type==="VIOLATION"||Se.type==="LATE")&&Se.points&&'
      + '(ze[Se.studentId||Se.studentName]=(ze[Se.studentId||Se.studentName]||0)+Se.points),ze),{}),[o])',
  },
  {
    id: 'B2 badge: label Total Sanksi',
    count: 1,
    from: 'children:["Sanksi: ",it[ze.studentName]," Poin"]',
    to: 'children:["Total Sanksi: ",it[ze.studentName]," Poin"]',
  },
  {
    id: 'B1 badge: baca per studentId',
    count: 4,
    from: 'it[ze.studentName]',
    to: 'it[ze.studentId||ze.studentName]',
  },
  {
    id: 'B4 badge: warna merah mulai 20 poin',
    count: 1,
    from: 'it[ze.studentId||ze.studentName]>20?"text-red-500"',
    to: 'it[ze.studentId||ze.studentName]>=20?"text-red-500"',
  },
  {
    id: 'B3 rekap siswa: kelompokkan per studentId',
    count: 1,
    from: 'const He=Se.studentName;He&&(ze[He]||(ze[He]={studentName:He,',
    to: 'const He=Se.studentName&&(Se.studentId||Se.studentName);He&&(ze[He]||(ze[He]={studentName:Se.studentName,studentId:Se.studentId,',
  },
];

let out = src;
for (const p of patches) {
  const count = out.split(p.from).length - 1;
  if (count !== p.count) fail(`${p.id}: anchor ditemukan ${count} kali (harus ${p.count}).`);
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
  if (html.split(ref).length - 1 !== 1) fail(`app.html tidak mereferensikan ${ref} tepat 1 kali.`);
  fs.writeFileSync(appHtmlPath, html.replace(ref, `./assets/${OUT_NAME}`), 'utf8');
  console.log(`app.html diarahkan ke assets/${OUT_NAME}`);
}
