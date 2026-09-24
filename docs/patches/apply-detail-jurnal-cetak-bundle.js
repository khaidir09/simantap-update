// Patch bundle frontend: modal "Detail Jurnal Mengajar" (komponen YTe) dan hasil cetaknya.
// Perbaikan:
//  D1 Tombol "Unduh PDF" dan "Cetak/Print" memanggil fungsi yang sama (bk -> window.print),
//     jadi digabung menjadi satu tombol "Cetak / PDF" (PDF lewat "Simpan sebagai PDF" di dialog cetak).
//  D2 Hapus baris "ID Laporan Sesi" di bawah judul cetakan.
//  D3 Waktu / Jam Pertemuan memakai jam pelajaran (Jam ke-.. + jam mulai-selesai),
//     bukan jam input jurnal (kolom period). Daftar jurnal kini ikut membawa field `jam`.
//  D4 Hapus baris "Kategori Jurnal" di tabel identitas cetakan.
//  D5 Beri jarak antar-butir pada bagian I (grid dipaksa display:block oleh mesin cetak
//     sehingga gap hilang dan butir menempel).
//  D6 Laporan kehadiran: hanya siswa yang tidak hadir; jika lengkap tampil keterangan nihil.
// Alamat dan kontak kop sudah dipisah 2 baris di fungsi kop (qa) bundle sumber.
// Prasyarat: patch pusat analisis (apply-pusat-analisis-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-detail-jurnal-cetak-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-kepsek1.js';
const OUT_NAME = 'index-CGWL69P7-jurnal1.js';
const SRC_SHA256 = '2e548f7c850d0e54b396b1cbeb451005cd7d19903b5ad94143fc29ceba02c9cf';

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

// CSS khusus area cetak; ditaruh di dalam #detail-print-pdf-area agar ikut ter-clone oleh bk().
const PRINT_CSS = '.smt-jurnal-sec1>*+*{margin-top:12px}.smt-jurnal-sec1 .smt-isi{line-height:1.5}.smt-jurnal-sec1 .smt-isi p{margin:0 0 6px}.smt-jurnal-sec1 .smt-isi p:last-child{margin-bottom:0}.smt-jurnal-sec1 .smt-isi ul,.smt-jurnal-sec1 .smt-isi ol{margin:0 0 6px;padding-left:18px}';

// Teks waktu pertemuan: "Jam ke-3-6 (10:10 - 12:55)"; fallback ke jam input bila data lama tak punya jam pelajaran.
const WAKTU_HELPER = 'const smtWaktu=(()=>{const j=String(e.jam||"").trim(),m=e.jamMulai&&e.jamSelesai?`${String(e.jamMulai).slice(0,5)} - ${String(e.jamSelesai).slice(0,5)}`:"";return j&&m?`Jam ke-${j} (${m})`:j?`Jam ke-${j}`:m||e.time||"-"})();';

const ABSEN_LABEL = '{SAKIT:"Sakit",IZIN:"Izin",ALFA:"Alfa",ALPHA:"Alfa"}';
const ABSEN_WARNA = '{SAKIT:"text-amber-600",IZIN:"text-blue-600"}';

const patches = [
  {
    id: 'D1 Gabungkan tombol Unduh PDF + Cetak/Print menjadi satu tombol',
    from: 'a.jsxs("button",{onClick:U,className:"px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-xs uppercase tracking-wider transition-all shadow-md flex items-center gap-2 cursor-pointer text-center",title:"Unduh laporan jurnal sebagai PDF",children:[a.jsx(Ti,{size:14})," Unduh PDF"]}),a.jsxs("button",{onClick:()=>bk("detail-print-pdf-area"),className:"px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-wider transition-all shadow-md flex items-center gap-2 cursor-pointer text-center",title:"Cetak Jurnal secara Fisik",children:[a.jsx(Ss,{size:14})," Cetak/Print"]}),',
    to: 'a.jsxs("button",{onClick:U,className:"px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-wider transition-all shadow-md flex items-center gap-2 cursor-pointer text-center",title:"Cetak jurnal, atau pilih \\"Simpan sebagai PDF\\" pada dialog cetak untuk mengunduh PDF",children:[a.jsx(Ss,{size:14})," Cetak / PDF"]}),',
  },
  {
    id: 'D3a Helper teks waktu pertemuan di komponen detail',
    from: 'const U=()=>{bk("detail-print-pdf-area")};',
    to: 'const U=()=>{bk("detail-print-pdf-area")};' + WAKTU_HELPER,
  },
  {
    id: 'D3b Daftar jurnal ikut membawa jam pelajaran (jam ke-)',
    from: 'jamMulai:ce.jamMulai||ce.jam_mulai||"",',
    to: 'jam:ce.jam||ce.jam_ke||"",jamMulai:ce.jamMulai||ce.jam_mulai||"",',
  },
  {
    id: 'D3c Cetakan: Waktu / Jam Pertemuan pakai jam pelajaran',
    from: 'children:e.time||"-"',
    to: 'children:smtWaktu',
  },
  {
    id: 'D3d Kartu Waktu di modal pakai jam pelajaran',
    from: 'children:e.jamMulai&&e.jamSelesai?`${e.jamMulai} - ${e.jamSelesai}`:e.time||"-"',
    to: 'children:smtWaktu',
  },
  {
    id: 'D2 Hapus "ID Laporan Sesi" + sisipkan CSS cetak',
    from: ',a.jsxs("p",{className:"text-[10px] font-bold text-slate-500 mt-1",children:["ID Laporan Sesi: ",e.id.toUpperCase()]})',
    to: `,a.jsx("style",{children:${JSON.stringify(PRINT_CSS)}})`,
  },
  {
    id: 'D4 Hapus baris Kategori Jurnal, Kelas / Rombel melebar',
    from: 'a.jsx("td",{className:"p-2.5 font-extrabold text-blue-700 border-r border-slate-300",children:e.className}),a.jsx("td",{className:"p-2.5 bg-slate-50 font-bold w-1/4 border-r border-slate-300",children:"Kategori Jurnal"}),a.jsx("td",{className:"p-2.5 font-bold text-slate-800",children:e.kategori||"Jurnal Mengajar"})',
    to: 'a.jsx("td",{colSpan:3,className:"p-2.5 font-extrabold text-blue-700",children:e.className})',
  },
  {
    id: 'D5a Bagian I: wadah butir berjarak',
    from: 'className:"grid grid-cols-1 gap-2.5",children:[',
    to: 'className:"smt-jurnal-sec1",children:[',
  },
  {
    id: 'D5b Bagian I: butir 3 & 4 jadi butir tersendiri (bukan grid)',
    from: 'a.jsxs("div",{className:"grid grid-cols-2 gap-4",children:[a.jsxs("div",{children:[a.jsx("span",{className:"font-extrabold text-slate-900 block pb-1",children:"3. Metode',
    to: 'a.jsxs(a.Fragment,{children:[a.jsxs("div",{children:[a.jsx("span",{className:"font-extrabold text-slate-900 block pb-1",children:"3. Metode',
  },
  {
    id: 'D5c Bagian I: jarak baris isi deskripsi kegiatan',
    from: 'className:"p-3 bg-slate-50 rounded border border-slate-200 leading-relaxed text-slate-700 prose prose-sm max-w-none",dangerouslySetInnerHTML:{__html:e.activities',
    to: 'className:"smt-isi p-3 bg-slate-50 rounded border border-slate-200 text-slate-700 max-w-none",dangerouslySetInnerHTML:{__html:e.activities',
  },
  {
    id: 'D6a Ringkasan kehadiran: tambah jumlah siswa',
    from: 'children:[a.jsxs("span",{className:"text-emerald-600",children:["H: ",p]})',
    to: 'children:[a.jsxs("span",{className:"text-slate-700",children:["Jumlah: ",h]}),a.jsxs("span",{className:"text-emerald-600",children:["H: ",p]})',
  },
  {
    id: 'D6b Tabel kehadiran: hanya siswa tidak hadir',
    from: 'a.jsxs("table",{className:"w-full border border-collapse text-left text-[11px] border-slate-300",children:[a.jsx("thead",{children:a.jsxs("tr",{className:"bg-slate-100 border-b border-slate-300 font-bold text-slate-700",children:[a.jsx("th",{className:"p-2 border border-slate-300",children:"Nama Siswa"}),a.jsx("th",{className:"p-2 border border-slate-300 text-center w-24",children:"Status"})]})}),a.jsx("tbody",{children:c.length>0?c.map(([I,M])=>{const z=A.find(ne=>ne.id===I),H=z?z.name:`Siswa (${I})`;let Y="text-rose-600";return M==="HADIR"&&(Y="text-emerald-600"),M==="SAKIT"&&(Y="text-amber-600"),M==="IZIN"&&(Y="text-blue-600"),a.jsxs("tr",{className:"border-b border-slate-200",children:[a.jsx("td",{className:"p-2 border border-slate-200 font-medium text-slate-850",children:H}),a.jsx("td",{className:`p-2 border border-slate-200 text-center font-bold ${Y} text-[10px]`,children:M})]},I)}):a.jsx("tr",{children:a.jsx("td",{colSpan:2,className:"p-3 text-center text-slate-400 italic font-semibold",children:"Data presensi tidak tersedia."})})})]})',
    to: 'h===0?a.jsx("p",{className:"p-2.5 text-center text-slate-400 italic font-semibold",children:"Data presensi tidak tersedia."}):u.length===0?a.jsxs("div",{className:"p-2.5 rounded border border-emerald-200 bg-emerald-50 font-bold text-emerald-700",children:["Nihil. Seluruh siswa hadir lengkap (",h," siswa)."]}):a.jsxs(a.Fragment,{children:[a.jsxs("p",{className:"font-bold text-slate-700",children:["Siswa tidak hadir: ",u.length," dari ",h," siswa"]}),a.jsxs("table",{className:"w-full border border-collapse text-left text-[11px] border-slate-300",children:[a.jsx("thead",{children:a.jsxs("tr",{className:"bg-slate-100 border-b border-slate-300 font-bold text-slate-700",children:[a.jsx("th",{className:"p-2 border border-slate-300 text-center w-10",children:"No"}),a.jsx("th",{className:"p-2 border border-slate-300",children:"Nama Siswa"}),a.jsx("th",{className:"p-2 border border-slate-300 text-center w-24",children:"Keterangan"})]})}),a.jsx("tbody",{children:u.map(([I,M])=>{const z=A.find(ne=>ne.id===I);return{id:I,nama:z?z.name:`Siswa (${I})`,st:M}}).sort((I,M)=>I.nama.localeCompare(M.nama,"id")).map((I,M)=>a.jsxs("tr",{className:"border-b border-slate-200",children:[a.jsx("td",{className:"p-2 border border-slate-200 text-center",children:M+1}),a.jsx("td",{className:"p-2 border border-slate-200 font-medium",children:I.nama}),a.jsx("td",{className:`p-2 border border-slate-200 text-center font-bold ${' + ABSEN_WARNA + '[I.st]||"text-rose-600"}`,children:' + ABSEN_LABEL + '[I.st]||I.st})]},I.id))})]})]})',
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
