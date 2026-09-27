// Patch bundle frontend: tampilkan tahun ajaran aktif (system_config.active_academic_year)
// di halaman Pelanggaran, Siswa Terlambat, Izin Keluar, dan Panggilan Orang Tua.
// Bug: komponen kesiswaan (wp) menulis "Tahun Ajaran 2025/2026" secara hardcode di card
//      Riwayat Aktivitas dan di header cetak (surat panggilan, rekap sanksi, laporan akumulasi).
//  T1 Ambil tahun ajaran aktif lewat get_config saat komponen dimuat.
//  T2 Card Riwayat Aktivitas memakai tahun ajaran aktif.
//  T3 Header cetak "Semester X • Tahun Ajaran ..." (3 tempat) memakai tahun ajaran aktif.
// Pemakaian (dari root deploy): node docs/patches/apply-tahun-ajaran-kesiswaan-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-berkasgtk1.js';
const OUT_NAME = 'index-CGWL69P7-tahunajaran1.js';
const SRC_SHA256 = 'c7843949bbb5a973217d06aa7c76ead65c54b8de9dec66dae723dff37cf798e5';

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
    id: 'T1 ambil tahun ajaran aktif',
    count: 1,
    from: 'ue.useEffect(()=>{Zf().then(ne).catch(console.error)},[]);const K=ue.useCallback(async()=>{if(t){u(!0);try{if(e==="PARENT_CALL")',
    to: 'ue.useEffect(()=>{Zf().then(ne).catch(console.error)},[]);const[__taAktif,__setTaAktif]=ue.useState("");'
      + 'ue.useEffect(()=>{et.config.get().then(c=>{const v=((c==null?void 0:c.active_academic_year)||"").trim();v&&__setTaAktif(v)}).catch(console.error)},[]);'
      + 'const K=ue.useCallback(async()=>{if(t){u(!0);try{if(e==="PARENT_CALL")',
  },
  {
    id: 'T2 card Riwayat Aktivitas',
    count: 1,
    from: 'children:"Tahun Ajaran 2025/2026"}),a.jsx("div",{className:"h-6 w-px bg-slate-100"}),a.jsx("p",{className:"text-xs font-bold text-slate-400 uppercase tracking-widest",children:"Riwayat Aktivitas"})',
    to: 'children:"Tahun Ajaran "+(__taAktif||"-")}),a.jsx("div",{className:"h-6 w-px bg-slate-100"}),a.jsx("p",{className:"text-xs font-bold text-slate-400 uppercase tracking-widest",children:"Riwayat Aktivitas"})',
  },
  {
    id: 'T3 header cetak',
    count: 3,
    from: '["Semester ",h," • Tahun Ajaran 2025/2026"]',
    to: '["Semester ",h," • Tahun Ajaran ",__taAktif||"-"]',
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
