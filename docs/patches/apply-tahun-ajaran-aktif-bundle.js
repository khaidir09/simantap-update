// Patch bundle frontend: hapus sisa hardcode tahun ajaran "2025/2026", pakai tahun ajaran aktif
// (system_config.active_academic_year) seperti patch apply-tahun-ajaran-kesiswaan-bundle.js.
//  A1 Presensi harian siswa (wali kelas): tidak lagi mengirim tahun "2025/2026" saat mengambil
//     dan menyimpan presensi. Backend sudah mengabaikan tahun saat membaca dan memakai tahun
//     ajaran aktif saat menyimpan (save_attendance_records).
//  A2 Laporan penilaian (harian/bulanan/semester): header "TAHUN AJARAN ..." layar & cetak (6 tempat)
//     memakai tahun ajaran aktif.
//  A3 Manajemen siswa: filter tahun ajaran, tahun ajaran impor, dan form siswa default ke tahun
//     ajaran aktif. Jika konfigurasi kosong/gagal dimuat, dihitung dari tanggal
//     (Juli–Desember = tahun ini/tahun depan, Januari–Juni = tahun lalu/tahun ini).
// Laporan kehadiran staf & guru sengaja tidak diubah.
// Pemakaian (dari root deploy): node docs/patches/apply-tahun-ajaran-aktif-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-tahunajaran1.js';
const OUT_NAME = 'index-CGWL69P7-tahunajaran2.js';
const SRC_SHA256 = 'c73e4735b3f696646e4d442e7ce160e8961d7225acd258c1270215c284668a49';

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

// Tahun ajaran aktif dari get_config; fallback dihitung dari tanggal hari ini.
const TA_FALLBACK = '(()=>{const d=new Date(),t=d.getFullYear();return d.getMonth()>=6?`${t}/${t+1}`:`${t-1}/${t}`})()';
const TA_AMBIL = `et.config.get().then(c=>((c==null?void 0:c.active_academic_year)||"").trim()||${TA_FALLBACK}).catch(()=>${TA_FALLBACK})`;

const patches = [
  {
    id: 'A1 presensi harian: ambil tanpa tahun',
    count: 1,
    from: 'getRecords(t,l,"2025/2026")',
    to: 'getRecords(t,l)',
  },
  {
    id: 'A1 presensi harian: simpan tanpa tahun',
    count: 1,
    from: 'saveRecords(K,"2025/2026")',
    to: 'saveRecords(K)',
  },
  {
    id: 'A2 laporan nilai: ambil tahun ajaran aktif',
    count: 1,
    from: '[nt,Vt]=B.useState(!1);B.useEffect(()=>{(async()=>{try{const pt=await fetch(nr+"?action=get_classes")',
    to: `[nt,Vt]=B.useState(!1),[__taAktif,__setTaAktif]=B.useState("");B.useEffect(()=>{${TA_AMBIL}.then(__setTaAktif)},[]);`
      + 'B.useEffect(()=>{(async()=>{try{const pt=await fetch(nr+"?action=get_classes")',
  },
  {
    id: 'A2 laporan nilai: header tahun ajaran',
    count: 6,
    from: '• TAHUN AJARAN 2025/2026`',
    to: '• TAHUN AJARAN ${__taAktif||"-"}`',
  },
  {
    id: 'A3 manajemen siswa: filter tahun awal kosong',
    count: 1,
    from: '[p,b]=ue.useState("2025/2026")',
    to: '[p,b]=ue.useState("")',
  },
  {
    id: 'A3 manajemen siswa: tahun impor awal kosong',
    count: 1,
    from: '[P,L]=ue.useState({year:"2025/2026",customYear:""})',
    to: '[P,L]=ue.useState({year:"",customYear:""})',
  },
  {
    id: 'A3 manajemen siswa: form siswa awal kosong',
    count: 1,
    from: 'enrollment_year:"",academic_year:"2025/2026"})',
    to: 'enrollment_year:"",academic_year:""})',
  },
  {
    id: 'A3 manajemen siswa: isi tahun ajaran aktif, muat data setelah tahun siap',
    count: 1,
    from: 'console.error("Fetch students error:",G)}s(!1)};ue.useEffect(()=>{de()},[u,p]);',
    to: 'console.error("Fetch students error:",G)}s(!1)};'
      + `ue.useEffect(()=>{${TA_AMBIL}.then(v=>{b(x=>x||v),L(x=>x.year?x:{...x,year:v})})},[]);`
      + 'ue.useEffect(()=>{p&&de()},[u,p]);',
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
