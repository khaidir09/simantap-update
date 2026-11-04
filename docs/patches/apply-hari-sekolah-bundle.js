// Patch bundle frontend: pengaturan Hari Sekolah (5 hari Senin–Jumat / 6 hari Senin–Sabtu) oleh ADMIN.
// Dokumentasi patch: docs/patches/tanggal-belum-presensi.md (§ Hari sekolah)
//  H1 Pengaturan (tab konfigurasi, di bawah "Verifikasi Jurnal"): pilihan "Hari Sekolah" → system_config.school_days_per_week.
//     Nilai ikut tersimpan lewat tombol simpan konfigurasi yang sama (save_system_config, ADMIN-only di backend).
// Prasyarat: patch Tanggal Belum Presensi (apply-tanggal-belum-presensi-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-hari-sekolah-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-tglpresensi1.js';
const OUT_NAME = 'index-CGWL69P7-harisekolah1.js';
const SRC_SHA256 = '520e1781d84d900f96b21bc12a64ab469a75e03815f4158e941cdad31f7d2897';
const CSS_NAME = 'index-CC1PBzaO.css';

// Akhir field "Verifikasi Jurnal" (hasil patch V6). ie = state form konfigurasi, le = setter-nya.
const FIELD_ANCHOR = 'Manual: jurnal menunggu verifikasi Kepala Sekolah lalu terkunci."})]})';
const FIELD_HARI =
  ',a.jsxs("div",{className:"space-y-1.5",children:[a.jsxs("label",{className:"text-[10px] font-black text-slate-500 uppercase tracking-widest flex items-center gap-1.5",children:[a.jsx(mo,{size:12})," Hari Sekolah"]}),' +
  'a.jsxs("select",{value:String(ie.school_days_per_week||"6")==="5"?"5":"6",onChange:Se=>le({...ie,school_days_per_week:Se.target.value}),className:"w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold focus:ring-2 focus:ring-blue-500 outline-none cursor-pointer",children:[' +
  'a.jsx("option",{value:"6",children:"6 hari — Senin s.d. Sabtu"}),a.jsx("option",{value:"5",children:"5 hari — Senin s.d. Jumat"})]}),' +
  'a.jsx("p",{className:"text-[10px] font-bold text-slate-400",children:"Dipakai untuk mengecek tanggal yang belum ada presensi di Dashboard Kelas dan Cetak Rekap Bulanan wali kelas. Sekolah 5 hari: Sabtu tidak dihitung."})]})';

const KELAS_PATCH = (FIELD_HARI.match(/className:"([^"]*)"/g) || [])
  .flatMap((m) => m.slice(11, -1).split(/\s+/))
  .filter(Boolean);

const patches = [{ id: 'H1 field Hari Sekolah', from: FIELD_ANCHOR, to: FIELD_ANCHOR + FIELD_HARI }];

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

  if (src.includes('school_days_per_week')) fail(`school_days_per_week sudah ada di ${SRC_NAME}.`);
  // Ikon mo (calendar) harus top-level dan tidak dideklarasikan ulang di komponen pengaturan
  if (!/[,;\s]mo=Nt\("calendar"/.test(src)) fail('ikon mo (calendar) tidak ditemukan.');
  const iAnchor = src.indexOf(FIELD_ANCHOR);
  const iKomp = src.lastIndexOf('function ', iAnchor);
  if (iAnchor < 0 || iKomp < 0) fail('anchor field Verifikasi Jurnal tidak ditemukan.');
  if (/[,{(\s]mo=/.test(src.slice(iKomp, iAnchor))) fail('mo dideklarasikan ulang di komponen pengaturan.');

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
