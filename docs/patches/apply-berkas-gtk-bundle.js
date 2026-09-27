// Patch bundle frontend: perbaiki hitungan kolom "Berkas" di tabel Kepegawaian (GTK).
// Bug: semua baris menampilkan 2/7 karena hitungan memakai Object.values(docs).filter(Boolean),
//      sehingga nilai placeholder boolean `ktp:true, ijazah:true` (default saat akun/GTK dibuat)
//      terhitung sebagai berkas terunggah, padahal berkas asli disimpan sebagai path (string).
//      pas_foto juga ikut terhitung walau bukan bagian dari checklist.
//  S1 Tabel GTK: hitung hanya dokumen wajib sesuai status kepegawaian yang bernilai path (string),
//     sama seperti tab "Berkas Digital" di detail GTK.
//       ASN  : KTP, KK, SK CPNS, SK Pangkat, SK Berkala, Ijazah, Sertifikat Pendidik (7)
//       PPPK : KTP, KK, SK PPPK, SK Berkala, Ijazah, Sertifikat Pendidik (6)
//       lain : KTP, KK, Ijazah, Sertifikat Pendidik (4)
//  S2 Form tambah GTK: default docs tidak lagi berisi placeholder true.
// Pemakaian (dari root deploy): node docs/patches/apply-berkas-gtk-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-mediadetail1.js';
const OUT_NAME = 'index-CGWL69P7-berkasgtk1.js';
const SRC_SHA256 = '6ee0cbcfeaf17c3db63ace38d92cc7b686e5e520f3393f63683c28c7b2f5f780';

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

const WAJIB = 'W.statusKepegawaian==="ASN"?["ktp","kk","skCpns","skPangkat","skBerkala","ijazah","sertifikat"]'
  + ':W.statusKepegawaian==="PPPK"?["ktp","kk","skPpk","skBerkala","ijazah","sertifikat"]'
  + ':["ktp","kk","ijazah","sertifikat"]';

const patches = [
  {
    id: 'S1 hitungan kolom Berkas tabel GTK',
    count: 1,
    from: 'const se=Object.values(W.docs).filter(Boolean).length,he=W.statusKepegawaian==="ASN"?7:W.statusKepegawaian==="PPPK"?6:4',
    to: `const __bkWajib=${WAJIB},__bkDocs=W.docs||{},se=__bkWajib.filter(k=>typeof __bkDocs[k]=="string"&&__bkDocs[k]!=="").length,he=__bkWajib.length`,
  },
  {
    id: 'S2 default docs form tambah GTK',
    count: 2,
    from: 'docs:{ktp:!0,kk:!0,skCpns:!1,skPpk:!1,skPangkat:!1,skBerkala:!1,ijazah:!0,sertifikat:!1}',
    to: 'docs:{}',
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
