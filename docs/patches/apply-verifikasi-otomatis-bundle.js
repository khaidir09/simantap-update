// Patch bundle frontend: Mode Verifikasi Jurnal — Otomatis SAH & tetap bisa diedit.
// Spesifikasi: docs/specs/verifikasi-jurnal-otomatis.md — Dokumentasi patch: docs/patches/verifikasi-jurnal-otomatis.md
//  V0 Helper global __vj* (mode verifikasi dari get_config yang sudah dimuat __jjMuat; gagal → OTOMATIS).
//  V1 Tombol Edit jurnal (daftar, modal detail): jurnal SAH tetap bisa diedit pemilik di mode OTOMATIS.
//  V2 Tombol Hapus jurnal (daftar, kartu): jurnal SAH tetap bisa dihapus pemilik di mode OTOMATIS.
//  V3 Tombol Verifikasi (daftar, kartu) dan "Verifikasi Sekarang" (modal detail) hanya di mode MANUAL.
//  V4 Tombol & panel "Setujui Jurnal Bulanan" hanya di mode MANUAL.
//  V5 Pusat Analisis: kalimat "jurnal menunggu verifikasi" hanya di mode MANUAL.
//  V6 Pengaturan (tab konfigurasi): pilihan "Verifikasi Jurnal" + konfirmasi saat 409 peringatan_verifikasi.
// Prasyarat: patch Jenjang Sekolah (apply-jenjang-sekolah-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-verifikasi-otomatis-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-jenjang1.js';
const OUT_NAME = 'index-CGWL69P7-verifotomatis1.js';
const SRC_SHA256 = '154b1e2ca04be709387a43464f46f912648a884df2e9b1f577abb9927d80f123';
const CSS_NAME = 'index-CC1PBzaO.css';

// ---------------------------------------------------------------------------
// V0 Helper global. Default OTOMATIS, selaras dengan backend (key belum ada → OTOMATIS).
// ---------------------------------------------------------------------------
const SNIPPET_HELPER =
  'let __vjState="OTOMATIS";' +
  'function __vjSet(m){__vjState=String(m||"").trim().toUpperCase()==="MANUAL"?"MANUAL":"OTOMATIS"}' +
  'function __vjOtomatis(){return __vjState!=="MANUAL"}\n';

// ---------------------------------------------------------------------------
// V6 Pengaturan: simpan konfigurasi (hasil patch jenjang J10) + konfirmasi mode verifikasi.
// ---------------------------------------------------------------------------
const SAVE_LAMA = 'const Ae=async(__jjKonf)=>{try{t(!0);const __jjR=await fetch(nr+"?action=save_system_config",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({configs:ie,konfirmasi_jenjang:__jjKonf===!0})});let __jjD=null;try{__jjD=await __jjR.json()}catch{}if(__jjR.status===409&&__jjD&&__jjD.peringatan_jenjang){__jjSetWarn(__jjD.peringatan_jenjang);return}if(__jjR.ok){GTe();';
// Argumen kedua: true = kirim ulang dengan konfirmasi pengesahan jurnal Pending.
// Batal → pilihan di form dikembalikan ke mode yang berlaku.
const SAVE_BARU = 'const Ae=async(__jjKonf,__vjKonf)=>{try{t(!0);const __jjR=await fetch(nr+"?action=save_system_config",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({configs:ie,konfirmasi_jenjang:__jjKonf===!0,konfirmasi_verifikasi:__vjKonf===!0})});let __jjD=null;try{__jjD=await __jjR.json()}catch{}if(__jjR.status===409&&__jjD&&__jjD.peringatan_jenjang){__jjSetWarn(__jjD.peringatan_jenjang);return}' +
  'if(__jjR.status===409&&__jjD&&__jjD.peringatan_verifikasi){if(confirm(`Ada ${__jjD.peringatan_verifikasi.pending} jurnal yang belum diverifikasi. Sahkan semuanya dan aktifkan mode Otomatis SAH?`)){await Ae(__jjKonf,!0)}else le(__vjX=>({...__vjX,journal_verification_mode:__vjState}));return}' +
  'if(__jjR.ok){GTe();__vjSet(ie.journal_verification_mode||"OTOMATIS");';

const FIELD_ANCHOR = 'onLanjut:()=>{__jjSetWarn(null),Ae(!0)}})]})';
const FIELD_VERIFIKASI =
  ',a.jsxs("div",{className:"space-y-1.5",children:[a.jsxs("label",{className:"text-[10px] font-black text-slate-500 uppercase tracking-widest flex items-center gap-1.5",children:[a.jsx(cr,{size:12})," Verifikasi Jurnal"]}),' +
  'a.jsxs("select",{value:String(ie.journal_verification_mode||"OTOMATIS").toUpperCase()==="MANUAL"?"MANUAL":"OTOMATIS",onChange:Se=>le({...ie,journal_verification_mode:Se.target.value}),className:"w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold focus:ring-2 focus:ring-blue-500 outline-none cursor-pointer",children:[' +
  'a.jsx("option",{value:"OTOMATIS",children:"Otomatis SAH — guru tetap bisa mengedit"}),a.jsx("option",{value:"MANUAL",children:"Perlu verifikasi Kepala Sekolah"})]}),' +
  'a.jsx("p",{className:"text-[10px] font-bold text-slate-400",children:"Otomatis: jurnal yang dikirim guru langsung SAH. Manual: jurnal menunggu verifikasi Kepala Sekolah lalu terkunci."})]})';

// Class yang ditulis langsung di teks patch (divalidasi terhadap CSS bundle)
const KELAS_PATCH = (FIELD_VERIFIKASI.match(/className:"([^"]*)"/g) || [])
  .flatMap((m) => m.slice(11, -1).split(/\s+/))
  .filter(Boolean);

const ROLE_KS = '(n==null?void 0:n.role)==="KEPALA_SEKOLAH"||(n==null?void 0:n.role)==="ADMIN"';

const patches = [
  { id: 'V0 helper __vj*', from: 'const __jjDaftar=', to: SNIPPET_HELPER + 'const __jjDaftar=' },
  {
    id: 'V0 mode dari get_config (__jjMuat)',
    from: 'if (c && c.school_level) __jjSet(c.school_level, c.school_level_sumber);',
    to: 'if (c && c.school_level) __jjSet(c.school_level, c.school_level_sumber);if (c) __vjSet(c.journal_verification_mode);',
  },
  {
    id: 'V1 tombol Edit (daftar)',
    from: '!Q.isVerified&&Q.teacherId===(n==null?void 0:n.uid)&&t&&a.jsxs("button",{onClick:()=>{t(Q.id)}',
    to: '(!Q.isVerified||__vjOtomatis())&&Q.teacherId===(n==null?void 0:n.uid)&&t&&a.jsxs("button",{onClick:()=>{t(Q.id)}',
  },
  {
    id: 'V1 tombol Edit (modal detail)',
    from: '!e.isVerified&&e.teacherId===(t==null?void 0:t.uid)&&s&&a.jsxs("button",{onClick:s,',
    to: '(!e.isVerified||__vjOtomatis())&&e.teacherId===(t==null?void 0:t.uid)&&s&&a.jsxs("button",{onClick:s,',
  },
  {
    id: 'V2 tombol Hapus (daftar, kartu)',
    count: 2,
    from: '(!Q.isVerified||(n==null?void 0:n.role)==="ADMIN")&&(Q.teacherId===(n==null?void 0:n.uid)',
    to: '(!Q.isVerified||(n==null?void 0:n.role)==="ADMIN"||__vjOtomatis())&&(Q.teacherId===(n==null?void 0:n.uid)',
  },
  {
    id: 'V3 tombol Verifikasi (daftar, kartu)',
    count: 2,
    from: '!Q.isVerified&&(' + ROLE_KS + ')&&',
    to: '!Q.isVerified&&!__vjOtomatis()&&(' + ROLE_KS + ')&&',
  },
  {
    id: 'V3 tombol "Verifikasi Sekarang" (modal detail)',
    from: 'l&&!e.isVerified&&a.jsxs("div",{className:"pt-2",children:[a.jsxs("button",{onClick:r,',
    to: 'l&&!e.isVerified&&!__vjOtomatis()&&a.jsxs("div",{className:"pt-2",children:[a.jsxs("button",{onClick:r,',
  },
  {
    id: 'V4 tombol "Setujui Jurnal Bulanan"',
    from: ',n&&(n.role==="KEPALA_SEKOLAH"||n.role==="ADMIN")&&a.jsxs("button",{onClick:()=>setSetujuOpen(!0),',
    to: ',n&&!__vjOtomatis()&&(n.role==="KEPALA_SEKOLAH"||n.role==="ADMIN")&&a.jsxs("button",{onClick:()=>setSetujuOpen(!0),',
  },
  {
    id: 'V4 panel Persetujuan Bulanan',
    from: 'setujuOpen&&a.jsx(ModalPersetujuanJurnal,',
    to: 'setujuOpen&&!__vjOtomatis()&&a.jsx(ModalPersetujuanJurnal,',
  },
  {
    id: 'V5 Pusat Analisis "menunggu verifikasi"',
    from: 'u=n.jurnal_belum_verifikasi>0?',
    to: 'u=n.mode_verifikasi_jurnal!=="OTOMATIS"&&n.jurnal_belum_verifikasi>0?',
  },
  { id: 'V6 simpan konfigurasi + 409 verifikasi', from: SAVE_LAMA, to: SAVE_BARU },
  { id: 'V6 field Verifikasi Jurnal', from: FIELD_ANCHOR, to: FIELD_ANCHOR + FIELD_VERIFIKASI },
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

  if (src.includes('__vj')) fail(`identifier __vj* sudah ada di ${SRC_NAME}.`);
  // Identifier bundle yang dipakai patch harus ada
  if (!/[,;\s]cr=Nt\("circle-check"/.test(src)) fail(`ikon cr (circle-check) tidak ditemukan di ${SRC_NAME}.`);

  let out = src;
  for (const p of patches) {
    const harus = p.count || 1;
    const count = out.split(p.from).length - 1;
    if (count !== harus) fail(`${p.id}: anchor ditemukan ${count} kali (harus ${harus}).`);
    out = out.split(p.from).join(p.to);
    console.log('OK  ' + p.id);
  }

  // Semua kunci verifikasi di UI jurnal harus sudah sadar mode
  for (const s of ['!Q.isVerified&&Q.teacherId===', '!e.isVerified&&e.teacherId===', 'l&&!e.isVerified&&a.jsxs(']) {
    if (out.includes(s)) fail(`masih ada sisa ${s} setelah patch.`);
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
