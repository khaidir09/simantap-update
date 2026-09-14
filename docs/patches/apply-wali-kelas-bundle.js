// Patch bundle frontend: menu Kesiswaan untuk Guru Wali Kelas.
// Spesifikasi: docs/specs/menu-kesiswaan-wali-kelas.md
// Pemakaian (dari root deploy): node docs/patches/apply-wali-kelas-bundle.js
// File bundle asli TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7.js';
const OUT_NAME = 'index-CGWL69P7-wali1.js';
const SRC_SHA256 = 'ba957b9bc049c2e95b1f7ca74cd4e993d0eff662c9bd5c7e9e0b3eb514cb04bd';

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

// Kunci kelas: sama dengan L$ / normalizeClassKey di backend
const KEY = 'String(_x??"").toLowerCase().replace(/[\\s_.-]/g,"")';
const key = (expr) => `((_x)=>${KEY})(${expr})`;

const patches = [
  {
    id: 'F1 sidebar: grup Kesiswaan tampil untuk GURU non-BK yang wali kelas',
    from: 'else if(Y.title==="Kesiswaan"||Y.title==="Umum & Ekskul")return!1',
    to: 'else if(Y.title==="Kesiswaan"&&!(s!=null&&s.class_homeroom)||Y.title==="Umum & Ekskul")return!1',
  },
  {
    id: 'F2 Presensi Kelas: dropdown kelas dibatasi ke kelas perwalian',
    from: 'o(me),me.length>0&&n(me[0].id)',
    to: `((_cl)=>{o(_cl),_cl.length>0&&n(_cl[0].id)})(e&&e.role==="GURU"?me.filter(_c=>${key('_c.id')}===${key('e.class_homeroom')}||${key('_c.name')}===${key('e.class_homeroom')}):me)`,
  },
  {
    // Nilai class_homeroom (mis. "X-AKT-1") bisa berbeda dari classes.id ("X_AKT_1"), jadi GURU mulai
    // dari "" (efek fetch melewati nilai kosong) dan kelas dipilih oleh F2 setelah daftar kelas dimuat.
    id: 'F3 Presensi Kelas: GURU tidak memuat kelas default "X-IPA-1"',
    from: 'ue.useState("X-IPA-1")',
    to: 'ue.useState(e&&e.role==="GURU"?"":"X-IPA-1")',
  },
  {
    id: 'F4 Insiden/Panggilan Ortu: dropdown kelas form dibatasi ke kelas perwalian',
    from: 'G(Se),Se.length>0&&Ee(wt=>({...wt,class:Se[0].id}))',
    to: `((_cl)=>{G(_cl),_cl.length>0&&Ee(wt=>({...wt,class:_cl[0].id}))})(t&&t.role==="GURU"&&Number(t.is_bk)!==1?Se.filter(_c=>${key('_c.id')}===${key('t.class_homeroom')}||${key('_c.name')}===${key('t.class_homeroom')}):Se)`,
  },
  // F5–F9: tampilkan nama kelas (class_homeroom_name dari login/session), bukan id kelas.
  // class_homeroom tetap dipakai untuk request API dan pencocokan.
  {
    id: 'F5 Sidebar Kelas Saya: label memakai nama kelas',
    from: 'label:`Dashboard ${s.class_homeroom}`',
    to: 'label:`Dashboard ${s.class_homeroom_name||s.class_homeroom}`',
  },
  {
    id: 'F6 Dashboard Kelas: variabel nama kelas untuk tampilan',
    from: 'function dot({user:e,onNavigate:t,activeSemester:n}){const r=e==null?void 0:e.class_homeroom,',
    to: 'function dot({user:e,onNavigate:t,activeSemester:n}){const r=e==null?void 0:e.class_homeroom,_rn=(e==null?void 0:e.class_homeroom_name)||r,',
  },
  {
    id: 'F7a Dashboard Kelas: judul dan deskripsi',
    from: 'title:`Dashboard Kelas ${r}`,description:`Rekap presensi, insiden, dan monitoring seluruh siswa kelas ${r} yang Anda ampu.`',
    to: 'title:`Dashboard Kelas ${_rn}`,description:`Rekap presensi, insiden, dan monitoring seluruh siswa kelas ${_rn} yang Anda ampu.`',
  },
  { id: 'F7b Dashboard Kelas: judul rekap bulanan', from: '"Rekap Presensi Bulanan — Kelas ",r]', to: '"Rekap Presensi Bulanan — Kelas ",_rn]' },
  { id: 'F7c Dashboard Kelas: judul rekap per siswa', from: '"Rekap Individual Siswa — Kelas ",r]', to: '"Rekap Individual Siswa — Kelas ",_rn]' },
  { id: 'F7d Dashboard Kelas: judul insiden', from: '"Insiden Siswa — Kelas ",r]', to: '"Insiden Siswa — Kelas ",_rn]' },
  { id: 'F8 Dashboard Kelas: kolom kelas per siswa', from: 'class:r,hadir:me.hadir', to: 'class:_rn,hadir:me.hadir' },
  {
    id: 'F9a Insiden: banner wali kelas',
    from: '"Wali Kelas ",t==null?void 0:t.class_homeroom]',
    to: '"Wali Kelas ",(t==null?void 0:t.class_homeroom_name)||(t==null?void 0:t.class_homeroom)]',
  },
  {
    id: 'F9b Insiden: keterangan wali kelas Anda',
    from: 'children:t.class_homeroom})," — Insiden ini dari kelas: "',
    to: 'children:t.class_homeroom_name||t.class_homeroom})," — Insiden ini dari kelas: "',
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
