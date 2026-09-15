// Patch bundle frontend: Perangkat Belajar per guru + perbaikan tampilan Modul Ajar/Administrasi.
// Spesifikasi: docs/specs/perangkat-belajar-per-guru.md
// Prasyarat: patch wali kelas, rekap bulanan, dan pembina ekskul sudah diterapkan (sumber = -ekskul1.js).
// Pemakaian (dari root deploy): node docs/patches/apply-perangkat-belajar-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-ekskul1.js';
const OUT_NAME = 'index-CGWL69P7-perangkat1.js';
const SRC_SHA256 = 'c5d02ea3b2a80424a8ec7aca3b2f013eeb7472f4944a88341968b1c4ca62e33e';

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
if (src.includes('__pb')) fail('prefiks identifier __pb sudah dipakai di bundle sumber.');

const SELECT_CLASS = 'bg-slate-50 border-none rounded-2xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-100 font-bold text-slate-600 max-w-xs';
const LOCK_TITLE = 'Dokumen sudah diverifikasi dan tidak dapat diubah';

// Jat: role, daftar akun GURU (khusus KS/ADMIN), filter guru terpilih, dan pembaca pesan error backend
const JAT_STATE = 'const __pbRole=e==null?void 0:e.role,__pbGuru=__pbRole==="GURU",__pbMon=__pbRole==="KEPALA_SEKOLAH"||__pbRole==="ADMIN",'
  + '[__pbGurus,__pbSetGurus]=ue.useState([]),[__pbSel,__pbSetSel]=ue.useState(""),'
  + '__pbErr=x=>{try{return JSON.parse(x.message).error||x.message}catch{return x&&x.message||"Periksa koneksi jaringan Anda."}};'
  + 'ue.useEffect(()=>{__pbMon&&et.users.list().then(u=>__pbSetGurus((Array.isArray(u)?u:[]).filter(g=>g.role==="GURU")'
  + '.sort((p,q)=>String(p.displayName).localeCompare(String(q.displayName),"id")))).catch(()=>{})},[__pbMon]);';

// Field tambahan dari backend yang dibawa ke item daftar
const EXTRA_FIELDS = 'teacherId:Qe.teacherId,teacherName:Qe.teacherName,canEdit:Qe.canEdit===!0,canDelete:Qe.canDelete===!0,fileUrl:Qe.fileUrl';

const TEACHER_SELECT = `__pbMon&&a.jsxs("select",{className:"${SELECT_CLASS}",value:__pbSel,onChange:re=>__pbSetSel(re.target.value),title:"Filter guru",`
  + 'children:[a.jsx("option",{value:"",children:"Semua guru"},"__all"),'
  + '...__pbGurus.map(g=>a.jsx("option",{value:g.uid,children:g.displayName},g.uid))]}),';

const LOCK_LIST = `__pbGuru&&re.status==="VERIFIED"&&a.jsx("span",{className:"text-[9px] font-black uppercase tracking-wider text-slate-400 mr-1",title:"${LOCK_TITLE}",children:"Terkunci"}),`;
const LOCK_GRID = `__pbGuru&&re.status==="VERIFIED"&&a.jsx("span",{className:"text-[9px] font-black uppercase tracking-wider text-slate-400",title:"${LOCK_TITLE}",children:"Terkunci"}),`;

const TARGET_PANEL_CLASS = 'md:col-span-5 bg-white p-6 md:p-8 rounded-[2.5rem] border border-slate-100 shadow-sm space-y-6 flex flex-col justify-between';

// Qat (modal detail) untuk dokumen unggahan: tombol unduh berfungsi + verifikasi KS/ADMIN
const QAT_OPEN_FILE = '()=>{const __pbF=e.fileUrl||e.rawRpp&&e.rawRpp.fileUrl||e.rawSilabus&&e.rawSilabus.fileUrl;__pbF?window.open(va+__pbF,"_blank"):alert("Berkas tidak ditemukan.")}';
const QAT_VERIFY = '((i==null?void 0:i.role)==="KEPALA_SEKOLAH"||(i==null?void 0:i.role)==="ADMIN")&&e.status!=="VERIFIED"&&a.jsxs("button",{onClick:()=>s==null?void 0:s(e.id),className:"flex-1 bg-emerald-600 text-white px-6 py-3.5 rounded-2xl font-bold text-sm hover:bg-emerald-700 shadow-emerald-100 hover:shadow-lg transition-all flex items-center justify-center gap-2",children:[a.jsx(cr,{size:18}),"Verifikasi & Setujui"]}),'
  + 'e.status==="VERIFIED"&&a.jsxs("div",{className:"flex-1 flex items-center justify-center gap-2 text-emerald-600 font-bold text-sm bg-emerald-50 rounded-2xl py-3.5 border border-emerald-100",children:[a.jsx(cr,{size:18}),"Terverifikasi"]}),';

const patches = [
  {
    id: 'F1a Jat mount: semua tipe selain SILABUS masuk daftar RPP/Modul/Administrasi',
    from: 'const Le=re.filter(Qe=>Qe.type==="RPP"||!Qe.type),lt=re.filter(Qe=>Qe.type==="SILABUS");',
    to: 'const Le=re.filter(Qe=>Qe.type!=="SILABUS"),lt=re.filter(Qe=>Qe.type==="SILABUS");',
  },
  {
    id: 'F1b Jat simpan RPP & Silabus (2 titik): filter tipe',
    from: 'y(Qe.filter(kt=>kt.type==="RPP"||!kt.type))',
    to: 'y(Qe.filter(kt=>kt.type!=="SILABUS"))',
    count: 2,
  },
  {
    id: 'F1c Jat verifikasi: filter tipe',
    from: 'y(Le.filter(lt=>lt.type==="RPP"||!lt.type))',
    to: 'y(Le.filter(lt=>lt.type!=="SILABUS"))',
  },
  {
    id: 'F1d Jat hapus: filter tipe',
    from: 'y(lt.filter(Qe=>Qe.type==="RPP"||!Qe.type))',
    to: 'y(lt.filter(Qe=>Qe.type!=="SILABUS"))',
  },
  {
    id: 'F1e Jat upload: filter tipe',
    from: 'y(Ct.filter(St=>St.type==="RPP"||!St.type||St.type==="MODUL"||St.type==="ADMINISTRASI"))',
    to: 'y(Ct.filter(St=>St.type!=="SILABUS"))',
  },
  {
    id: 'F6a Jat: state role, daftar guru, filter guru, pembaca error',
    from: 'ue.useEffect(()=>{Zf().then(U).catch(console.error)},[]);const[I,M]=ue.useState(t||"GANJIL")',
    to: `ue.useEffect(()=>{Zf().then(U).catch(console.error)},[]);${JAT_STATE}const[I,M]=ue.useState(t||"GANJIL")`,
  },
  {
    id: 'F2a Jat pemetaan jn: tipe & mode interaktif mengikuti data (RPP/MODUL/ADMINISTRASI)',
    from: 'const re=b.map(Qe=>({id:Qe.id,title:Qe.title,type:"RPP",semester:Qe.semester,date:Qe.date||"Baru Saja",size:Qe.size||"Interaktif RPP",status:Qe.status||"PENDING",description:Qe.topic,isInteractive:!0,rawRpp:Qe}))',
    to: `const re=b.map(Qe=>({id:Qe.id,title:Qe.title,type:Qe.type||"RPP",semester:Qe.semester,date:Qe.date||"Baru Saja",size:Qe.size||(Qe.isInteractive===!1?"Dokumen Unggahan":"Interaktif RPP"),status:Qe.status||"PENDING",description:Qe.topic,isInteractive:Qe.isInteractive!==!1,rawRpp:Qe,${EXTRA_FIELDS}}))`,
  },
  {
    id: 'F2b Jat pemetaan jn: Silabus (interaktif atau unggahan)',
    from: 'Oe=N.map(Qe=>({id:Qe.id,title:Qe.title,type:"SILABUS",semester:Qe.semester,date:Qe.date||"Baru Saja",size:Qe.size||"Interaktif Silabus",status:Qe.status||"PENDING",description:`${Qe.subject} - ${Qe.classSession}`,isInteractive:!0,rawSilabus:Qe}))',
    to: `Oe=N.map(Qe=>({id:Qe.id,title:Qe.title,type:"SILABUS",semester:Qe.semester,date:Qe.date||"Baru Saja",size:Qe.size||(Qe.isInteractive===!1?"Dokumen Unggahan":"Interaktif Silabus"),status:Qe.status||"PENDING",description:Qe.isInteractive===!1?Qe.topic:\`\${Qe.subject} - \${Qe.classSession}\`,isInteractive:Qe.isInteractive!==!1,rawSilabus:Qe,${EXTRA_FIELDS}}))`,
  },
  {
    id: 'F6b Jat: filter guru diterapkan sebelum pencarian & penghitung',
    from: 'W=jn.filter(re=>re.semester===I)',
    to: 'W=jn.filter(re=>re.semester===I&&(!__pbMon||__pbSel===""||re.teacherId===__pbSel))',
  },
  {
    id: 'F10a Jat simpan RPP: pesan error backend',
    from: 'console.error("Gagal menyimpan ke MySQL:",Qe),alert("Penyimpanan server gagal. Periksa koneksi jaringan Anda.")',
    to: 'console.error("Gagal menyimpan ke MySQL:",Qe),alert("Penyimpanan server gagal: "+__pbErr(Qe))',
  },
  {
    id: 'F10b Jat simpan Silabus: pesan error backend',
    from: 'console.error("Gagal menyimpan Silabus ke MySQL:",Qe),alert("Penyimpanan server gagal. Periksa koneksi jaringan Anda.")',
    to: 'console.error("Gagal menyimpan Silabus ke MySQL:",Qe),alert("Penyimpanan server gagal: "+__pbErr(Qe))',
  },
  {
    id: 'F10c Jat verifikasi: baca respons error',
    from: 'try{if(!(await fetch(nr+"?action=verify_lesson_rpp",',
    to: 'try{const __pbR=await fetch(nr+"?action=verify_lesson_rpp",',
  },
  {
    id: 'F10d Jat verifikasi: lempar pesan backend',
    from: 'body:JSON.stringify({id:re})})).ok)throw new Error("Gagal verifikasi");',
    to: 'body:JSON.stringify({id:re})});if(!__pbR.ok)throw new Error(await __pbR.text()||"Gagal verifikasi");',
  },
  {
    id: 'F10e Jat verifikasi: tampilkan pesan',
    from: 'alert("RPP/Silabus berhasil diverifikasi!")}catch{alert("Gagal memverifikasi dokumen.")}',
    to: 'alert("Dokumen berhasil diverifikasi!")}catch(__pbE){alert("Gagal memverifikasi dokumen: "+__pbErr(__pbE))}',
  },
  {
    id: 'F10f Jat hapus: pesan error backend',
    from: 'console.error("Gagal menghapus dokumen:",lt),alert("Hapus dokumen gagal. Periksa koneksi internet Anda.")',
    to: 'console.error("Gagal menghapus dokumen:",lt),alert("Hapus dokumen gagal: "+__pbErr(lt))',
  },
  {
    id: 'F10g Jat upload: pesan error backend',
    from: 'console.error("Gagal upload dokumen:",Oe),alert("Gagal mengunggah dokumen. Silakan coba lagi.")',
    to: 'console.error("Gagal upload dokumen:",Oe),alert("Gagal mengunggah dokumen: "+__pbErr(Oe))',
  },
  {
    id: 'F5a Jat: tombol Buat RPP Interaktif hanya GURU',
    from: 'children:[a.jsxs("button",{type:"button",onClick:()=>{ke(null),Q(""),',
    to: 'children:[__pbGuru&&a.jsxs("button",{type:"button",onClick:()=>{ke(null),Q(""),',
  },
  {
    id: 'F5b Jat: tombol Buat Silabus Interaktif hanya GURU',
    from: 'a.jsxs("button",{type:"button",onClick:()=>{He(null),Kt(""),',
    to: '__pbGuru&&a.jsxs("button",{type:"button",onClick:()=>{He(null),Kt(""),',
  },
  {
    id: 'F5c Jat: tombol Upload Dokumen Baru hanya GURU',
    from: 'a.jsxs("button",{onClick:()=>ne(!0),className:"bg-blue-600 hover:bg-blue-700 text-white px-5 py-3',
    to: '__pbGuru&&a.jsxs("button",{onClick:()=>ne(!0),className:"bg-blue-600 hover:bg-blue-700 text-white px-5 py-3',
  },
  {
    id: 'F8 Jat: panel Sisa Target hanya bila satu guru dipilih (KS/ADMIN)',
    from: `a.jsxs("div",{className:"${TARGET_PANEL_CLASS}",children:[a.jsxs("div",{className:"space-y-1",children:[a.jsxs("h3",{className:"text-sm font-black text-slate-800 uppercase tracking-wider flex items-center",children:[a.jsx(Vo,`,
    to: `__pbMon&&__pbSel===""?a.jsx("div",{className:"md:col-span-5 bg-white p-6 md:p-8 rounded-[2.5rem] border border-slate-100 shadow-sm flex items-center justify-center text-center",children:a.jsx("p",{className:"text-xs font-bold text-slate-400",children:"Pilih guru untuk melihat sisa target dokumen semester."})}):a.jsxs("div",{className:"${TARGET_PANEL_CLASS}",children:[a.jsxs("div",{className:"space-y-1",children:[a.jsxs("h3",{className:"text-sm font-black text-slate-800 uppercase tracking-wider flex items-center",children:[a.jsx(Vo,`,
  },
  {
    id: 'F6c Jat toolbar: dropdown filter guru (KS/ADMIN)',
    from: 'a.jsxs("div",{className:"flex items-center gap-3",children:[a.jsxs("div",{className:"relative",children:[a.jsx(Ui,{size:16,className:"absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"}),a.jsx("input",{type:"text",placeholder:"Cari dokumen...",',
    to: `a.jsxs("div",{className:"flex flex-wrap items-center gap-3",children:[${TEACHER_SELECT}a.jsxs("div",{className:"relative",children:[a.jsx(Ui,{size:16,className:"absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"}),a.jsx("input",{type:"text",placeholder:"Cari dokumen...",`,
  },
  {
    id: 'F7a Jat tabel: header kolom Guru (KS/ADMIN)',
    from: 'a.jsx("th",{className:"px-8 py-5",children:"Nama Dokumen"}),',
    to: 'a.jsx("th",{className:"px-8 py-5",children:"Nama Dokumen"}),__pbMon&&a.jsx("th",{className:"px-8 py-5",children:"Guru"}),',
  },
  {
    id: 'F7b Jat tabel: colSpan baris kosong',
    from: 'a.jsxs("td",{colSpan:7,className:"text-center p-12 text-slate-450 text-slate-400 font-bold text-xs",children:["Belum ada dokumen ",',
    to: 'a.jsxs("td",{colSpan:__pbMon?8:7,className:"text-center p-12 text-slate-450 text-slate-400 font-bold text-xs",children:["Belum ada dokumen ",',
  },
  {
    id: 'F7c Jat tabel: sel kolom Guru (KS/ADMIN)',
    from: 'a.jsx("span",{className:"font-bold text-slate-800 text-sm",children:re.title})]})}),',
    to: 'a.jsx("span",{className:"font-bold text-slate-800 text-sm",children:re.title})]})}),__pbMon&&a.jsx("td",{className:"px-8 py-5 text-xs font-bold text-slate-600",children:re.teacherName||"—"}),',
  },
  {
    id: 'F7d Jat grid: nama guru di kartu (KS/ADMIN)',
    from: 'a.jsx("h4",{className:"font-bold text-slate-800 text-sm leading-tight mb-4 group-hover:text-blue-600 transition-colors",children:re.title}),',
    to: 'a.jsx("h4",{className:"font-bold text-slate-800 text-sm leading-tight mb-4 group-hover:text-blue-600 transition-colors",children:re.title}),__pbMon&&a.jsx("p",{className:"text-[10px] font-bold text-slate-500 -mt-1 mb-4",children:re.teacherName||"—"}),',
  },
  {
    id: 'F4a Jat tabel: label Terkunci untuk dokumen VERIFIED milik guru',
    from: 'a.jsx("button",{onClick:()=>me(re),className:"p-2 text-slate-400 hover:text-blue-600 transition-colors",title:"Lihat Detail Perangkat"',
    to: `${LOCK_LIST}a.jsx("button",{onClick:()=>me(re),className:"p-2 text-slate-400 hover:text-blue-600 transition-colors",title:"Lihat Detail Perangkat"`,
  },
  {
    id: 'F4b Jat tabel: tombol Edit interaktif hanya bila canEdit',
    from: 're.isInteractive?a.jsxs(a.Fragment,{children:[a.jsx("button",{onClick:()=>{if(re.type==="SILABUS")',
    to: 're.isInteractive?a.jsxs(a.Fragment,{children:[re.canEdit&&a.jsx("button",{onClick:()=>{if(re.type==="SILABUS")',
  },
  {
    id: 'F4c Jat tabel: tombol Hapus interaktif hanya bila canDelete',
    from: 'a.jsx("button",{onClick:()=>Zn(re.id,!0,re.type==="SILABUS"),className:"p-2 text-slate-400 hover:text-rose-600',
    to: 're.canDelete&&a.jsx("button",{onClick:()=>Zn(re.id,!0,re.type==="SILABUS"),className:"p-2 text-slate-400 hover:text-rose-600',
  },
  {
    id: 'F3a+F4d Jat tabel: Hapus dokumen unggahan lewat server, hanya bila canDelete',
    from: 'a.jsx("button",{onClick:()=>Zn(re.id,!1),className:"p-2 text-slate-400 hover:text-rose-600',
    to: 're.canDelete&&a.jsx("button",{onClick:()=>Zn(re.id,!0),className:"p-2 text-slate-400 hover:text-rose-600',
  },
  {
    id: 'F4e Jat grid: label Terkunci untuk dokumen VERIFIED milik guru',
    from: 'a.jsx("button",{onClick:()=>me(re),className:"text-[10px] font-bold text-blue-600 hover:underline",children:"Lihat"}),',
    to: `${LOCK_GRID}a.jsx("button",{onClick:()=>me(re),className:"text-[10px] font-bold text-blue-600 hover:underline",children:"Lihat"}),`,
  },
  {
    id: 'F4f Jat grid: tombol Edit interaktif hanya bila canEdit',
    from: 're.isInteractive&&a.jsxs(a.Fragment,{children:[a.jsx("button",{onClick:()=>{if(re.type==="SILABUS")',
    to: 're.isInteractive&&a.jsxs(a.Fragment,{children:[re.canEdit&&a.jsx("button",{onClick:()=>{if(re.type==="SILABUS")',
  },
  {
    id: 'F4g Jat grid: tombol Hapus interaktif hanya bila canDelete',
    from: 'a.jsx("button",{onClick:()=>Zn(re.id,!0,re.type==="SILABUS"),className:"text-[10px] font-bold text-rose-600 hover:underline"',
    to: 're.canDelete&&a.jsx("button",{onClick:()=>Zn(re.id,!0,re.type==="SILABUS"),className:"text-[10px] font-bold text-rose-600 hover:underline"',
  },
  {
    id: 'F3b+F4h Jat grid: Hapus dokumen unggahan lewat server, hanya bila canDelete',
    from: 'a.jsx("button",{onClick:()=>Zn(re.id,!1),className:"text-[10px] font-bold text-rose-500 hover:underline"',
    to: 're.canDelete&&a.jsx("button",{onClick:()=>Zn(re.id,!0),className:"text-[10px] font-bold text-rose-500 hover:underline"',
  },
  {
    id: 'F9a Jat: modal upload menerima semester aktif',
    from: 'Y&&a.jsx(Zat,{onClose:()=>ne(!1),onUpload:re=>{Ve(re)}})',
    to: 'Y&&a.jsx(Zat,{onClose:()=>ne(!1),onUpload:re=>{Ve(re)},defaultSemester:I})',
  },
  {
    id: 'F9b Zat: default semester = semester aktif',
    from: 'function Zat({onClose:e,onUpload:t}){const[n,r]=ue.useState(""),[s,i]=ue.useState("RPP"),[o,l]=ue.useState("GENAP")',
    to: 'function Zat({onClose:e,onUpload:t,defaultSemester:__pbSem}){const[n,r]=ue.useState(""),[s,i]=ue.useState("RPP"),[o,l]=ue.useState(__pbSem||"GENAP")',
  },
  {
    id: 'F12a Qat unggahan: nama pengunggah',
    from: 'children:"AD"}),a.jsx("p",{className:"text-sm font-bold text-slate-700",children:"Admin Jurusan / Guru"})',
    to: 'children:String(e.teacherName||"G").trim().slice(0,2).toUpperCase()}),a.jsx("p",{className:"text-sm font-bold text-slate-700",children:e.teacherName||"Guru"})',
  },
  {
    id: 'F12b Qat unggahan: keterangan izin akses',
    from: 'a.jsx("p",{className:"text-sm font-bold",children:"Publik (Guru & Staf)"})',
    to: 'a.jsx("p",{className:"text-sm font-bold",children:"Guru pemilik, Kepala Sekolah & Admin"})',
  },
  {
    id: 'F13 Qat unggahan: tombol Unduh Berkas berfungsi + verifikasi KS/ADMIN',
    from: 'children:"Batal"}),a.jsxs("button",{className:"flex-1 bg-blue-600 text-white px-6 py-3.5 rounded-2xl font-bold text-sm hover:bg-blue-700 hover:shadow-lg shadow-blue-100 transition-all flex items-center justify-center gap-2",children:[a.jsx(Ti,{size:18}),"Unduh Berkas"]})',
    to: `children:"Batal"}),${QAT_VERIFY}a.jsxs("button",{onClick:${QAT_OPEN_FILE},className:"flex-1 bg-blue-600 text-white px-6 py-3.5 rounded-2xl font-bold text-sm hover:bg-blue-700 hover:shadow-lg shadow-blue-100 transition-all flex items-center justify-center gap-2",children:[a.jsx(Ti,{size:18}),"Unduh Berkas"]})`,
  },
];

let out = src;
for (const p of patches) {
  const expected = p.count || 1;
  const count = out.split(p.from).length - 1;
  if (count !== expected) fail(`${p.id}: anchor ditemukan ${count} kali (harus ${expected}).`);
  out = out.split(p.from).join(p.to);
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
