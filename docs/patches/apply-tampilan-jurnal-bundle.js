// Patch bundle frontend: rapikan tampilan halaman Jurnal Mengajar.
//  T1 Kartu jurnal: hapus badge kategori; baris badge hanya tampil jika ada penilaian.
//  T2 Tabel jurnal: hapus badge kategori di bawah nama guru (konsisten dengan kartu).
//  T3 Modal detail, "Siswa Tidak Hadir": tampilkan nama saja (tanpa NISN), nama tidak dipotong.
//  T4 Modal detail, gaya item siswa tidak hadir: hapus truncate, beri jarak nama-keterangan.
//  T5 Cetak/PDF: beri jarak antara bagian III (kehadiran) dan IV (penilaian). Mesin cetak (bk)
//     memaksa grid menjadi display:block sehingga gap-6 hilang dan bagian IV menempel.
//  T6 Helper __smtJamJurnal: "Jam ke-.. (mulai - selesai)" dari jam pelajaran. Kolom period
//     (field time) adalah jam input jurnal, bukan jam pertemuan, jadi tidak dipakai lagi.
//  T7 Tabel jurnal: waktu memakai jam pelajaran.
//  T8 Kartu jurnal: waktu memakai jam pelajaran.
//  T9 Modal detail + cetakan: kotak Waktu tidak lagi jatuh ke jam input.
//  T10 Modal detail: badge "Pukul" hanya dari jam mulai-selesai (detik dipangkas).
//  T11 Modal detail, "Hasil Penilaian Langsung": nama siswa tanpa NISN.
// Prasyarat: patch Dispensasi Siswa (apply-dispensasi-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-tampilan-jurnal-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-dispensasi1.js';
const OUT_NAME = 'index-CGWL69P7-tampiljurnal2.js';
// Hasil versi sebelumnya dari skrip ini; app.html yang masih menunjuk ke sini ikut dialihkan.
const OLD_OUT_NAMES = ['index-CGWL69P7-tampiljurnal1.js'];
const SRC_SHA256 = '3aaf8c2081d2646b692fe03abad126b67a969be48bc6f672e0e417dca5d3cd71';
const CSS_NAME = 'index-CC1PBzaO.css';

const srcPath = path.join(ROOT, 'assets', SRC_NAME);
const outPath = path.join(ROOT, 'assets', OUT_NAME);
const cssPath = path.join(ROOT, 'assets', CSS_NAME);
const appHtmlPath = path.join(ROOT, 'app.html');

function fail(msg) {
  console.error('GAGAL: ' + msg);
  process.exit(1);
}

const src = fs.readFileSync(srcPath, 'utf8');
const hash = crypto.createHash('sha256').update(fs.readFileSync(srcPath)).digest('hex');
if (hash !== SRC_SHA256) fail(`hash ${SRC_NAME} berbeda (${hash}). Bundle sudah berubah, cari ulang anchor secara manual.`);

const BADGE_PENILAIAN = 'a.jsx("span",{className:"text-[8px] bg-blue-50 text-slate px-2.5 py-0.5 rounded-md border border-blue-100 font-black uppercase tracking-wider flex items-center gap-1",children:"Penilaian"})';
const KELAS_PATCH = ['min-w-0', 'gap-3'];

const patches = [
  {
    id: 'T1 kartu jurnal tanpa badge kategori',
    from: 'a.jsxs("div",{className:"flex gap-2",children:[a.jsx("span",{className:"text-[8px] bg-blue-50/50 text-slate px-2.5 py-0.5 rounded-md border border-blue-100/50 font-black uppercase tracking-wider",children:Q.kategori||"Jurnal Utama"}),Q.has_assessment&&' + BADGE_PENILAIAN + ']})',
    to: 'Q.has_assessment&&a.jsx("div",{className:"flex gap-2",children:' + BADGE_PENILAIAN + '})',
  },
  {
    id: 'T2 tabel jurnal tanpa badge kategori',
    from: ',Q.kategori&&a.jsx("span",{className:"text-[9px] bg-blue-50 text-slate-600 px-2 py-0.5 rounded-lg border border-blue-100 font-black uppercase leading-none flex items-center justify-center",children:Q.kategori})]})',
    to: ']})',
  },
  {
    id: 'T3 siswa tidak hadir tanpa NISN',
    from: 'const z=A.find(K=>K.id===I),H=z!=null&&z.nisn?`${z.nisn} - `:"",Y=z?`${H}${z.name}`:`Siswa (${I})`;',
    to: 'const z=A.find(K=>K.id===I),Y=z?z.name:`Siswa (${I})`;',
  },
  {
    id: 'T4 nama siswa tidak hadir tidak dipotong',
    from: 'a.jsxs("div",{className:"p-3 bg-white rounded-xl border border-emerald-100/50 flex justify-between items-center shadow-sm",children:[a.jsx("span",{className:"text-xs font-bold text-slate-700 truncate",children:Y})',
    to: 'a.jsxs("div",{className:"p-3 bg-white rounded-xl border border-emerald-100/50 flex justify-between items-center gap-3 shadow-sm",children:[a.jsx("span",{className:"text-xs font-bold text-slate-700 min-w-0",children:Y})',
  },
  {
    id: 'T5 jarak bagian III dan IV pada cetakan',
    from: 'a.jsxs("div",{className:"grid grid-cols-1 md:grid-cols-2 gap-6 mb-8 print:flex print:flex-col",children:[a.jsxs("div",{children:[a.jsx("div",{className:"border-b-2 border-slate-800 pb-1 mb-3",children:a.jsx("h4",{className:"text-[11px] font-black uppercase text-slate-950 tracking-wider",children:"III. LAPORAN KEHADIRAN SISWA"})})',
    to: 'a.jsxs("div",{className:"smt-jurnal-sec34 grid grid-cols-1 md:grid-cols-2 gap-6 mb-8 print:flex print:flex-col",children:[a.jsxs("div",{children:[a.jsx("div",{className:"border-b-2 border-slate-800 pb-1 mb-3",children:a.jsx("h4",{className:"text-[11px] font-black uppercase text-slate-950 tracking-wider",children:"III. LAPORAN KEHADIRAN SISWA"})})',
  },
  {
    id: 'T5b gaya jarak bagian III dan IV',
    from: '.smt-jurnal-sec1 .smt-isi ul,.smt-jurnal-sec1 .smt-isi ol{margin:0 0 6px;padding-left:18px}"',
    to: '.smt-jurnal-sec1 .smt-isi ul,.smt-jurnal-sec1 .smt-isi ol{margin:0 0 6px;padding-left:18px}.smt-jurnal-sec34>*+*{margin-top:24px}"',
  },
  {
    id: 'T6 helper jam pelajaran',
    from: 'function JTe({month:e,year:t,journals:n,onClose:r,kopConfig:s,printKop:i}){',
    to: 'function __smtJamJurnal(e){const j=String(e.jam||"").trim(),m=e.jamMulai&&e.jamSelesai?`${String(e.jamMulai).slice(0,5)} - ${String(e.jamSelesai).slice(0,5)}`:"";return j&&m?`Jam ke-${j} (${m})`:j?`Jam ke-${j}`:m}'
      + 'function JTe({month:e,year:t,journals:n,onClose:r,kopConfig:s,printKop:i}){',
  },
  {
    id: 'T7 tabel jurnal waktu jam pelajaran',
    from: 'className:"text-[10px] font-bold text-slate-400 uppercase tracking-tighter",children:Q.time||"Waktu tidak diatur"}',
    to: 'className:"text-[10px] font-bold text-slate-400 uppercase tracking-tighter",children:__smtJamJurnal(Q)||"Waktu tidak diatur"}',
  },
  {
    id: 'T8 kartu jurnal waktu jam pelajaran',
    from: '{weekday:"short",day:"numeric",month:"short"})," • ",Q.time||"Waktu tidak diatur"]',
    to: '{weekday:"short",day:"numeric",month:"short"})," • ",__smtJamJurnal(Q)||"Waktu tidak diatur"]',
  },
  {
    id: 'T9 kotak Waktu tanpa jam input',
    from: 'return j&&m?`Jam ke-${j} (${m})`:j?`Jam ke-${j}`:m||e.time||"-"})();',
    to: 'return j&&m?`Jam ke-${j} (${m})`:j?`Jam ke-${j}`:m||"-"})();',
  },
  {
    id: 'T10 badge Pukul dari jam mulai-selesai',
    from: 'e.jamMulai&&e.jamSelesai||e.time?a.jsxs("span",{className:"w-fit text-[10px] bg-blue-50 text-blue-600 px-3 py-1 rounded-full border border-blue-100 font-extrabold uppercase tracking-wider",children:["Pukul: ",e.jamMulai&&e.jamSelesai?`${e.jamMulai} - ${e.jamSelesai}`:e.time]}):null',
    to: 'e.jamMulai&&e.jamSelesai?a.jsxs("span",{className:"w-fit text-[10px] bg-blue-50 text-blue-600 px-3 py-1 rounded-full border border-blue-100 font-extrabold uppercase tracking-wider",children:["Pukul: ",`${String(e.jamMulai).slice(0,5)} - ${String(e.jamSelesai).slice(0,5)}`]}):null',
  },
  {
    id: 'T11 penilaian langsung tanpa NISN',
    from: 'children:(()=>{const M=A.find(H=>H.id===I.student_id);return`${M!=null&&M.nisn?`${M.nisn} - `:""}${I.student_name}`})()}',
    to: 'children:I.student_name||(()=>{const M=A.find(H=>H.id===I.student_id);return M?M.name:`Siswa (${I.student_id})`})()}',
  },
];

// ---------------------------------------------------------------------------
const css = fs.readFileSync(cssPath, 'utf8');
const cssEscape = (c) => c.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
const hilang = KELAS_PATCH.filter((c) => {
  const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
  return !re.test(css);
});
if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));

for (const nama of ['smt-jurnal-sec34', '__smtJamJurnal']) {
  if (src.includes(nama)) fail(`${nama} sudah ada di ${SRC_NAME}.`);
}

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
  const ref = [SRC_NAME, ...OLD_OUT_NAMES].map((n) => `./assets/${n}`).find((r) => html.includes(r));
  if (!ref || html.split(ref).length - 1 !== 1) fail(`app.html tidak mereferensikan ./assets/${SRC_NAME} tepat 1 kali.`);
  fs.writeFileSync(appHtmlPath, html.replace(ref, `./assets/${OUT_NAME}`), 'utf8');
  console.log(`app.html diarahkan ke assets/${OUT_NAME}`);
}
