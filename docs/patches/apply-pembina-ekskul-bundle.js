// Patch bundle frontend: Ekstrakurikuler untuk Admin, guru pembina, dan wali kelas.
// Spesifikasi: docs/specs/pembina-ekstrakurikuler.md
// Prasyarat: patch wali kelas dan rekap bulanan sudah diterapkan (sumber = -wali2.js).
// Pemakaian (dari root deploy): node docs/patches/apply-pembina-ekskul-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-wali2.js';
const OUT_NAME = 'index-CGWL69P7-ekskul1.js';
const SRC_SHA256 = 'b4b4a68973c30eb13d1b7d6ea6d69141dbd5b3418caacce596cee7810f30c1f3';

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
if (src.includes('__ek')) fail('prefiks identifier __ek sudah dipakai di bundle sumber.');

// User di sidebar (s) punya akses ekskul: pembina atau wali kelas terkait (dihitung backend di login/session)
const HAS_EKSKUL = 's!=null&&(Number(s.ekskul_coach_count)>0||Number(s.ekskul_homeroom_count)>0)';

const INPUT_CLASS = 'w-full bg-slate-50 border border-slate-100 rounded-2xl p-4 text-xs font-bold outline-none focus:ring-2 focus:ring-blue-100';

// Form ekskul (not): dropdown akun GURU untuk Admin, teks read-only untuk pembina
const COACH_FIELD = `__ekAdm?a.jsx("select",{className:"${INPUT_CLASS}",value:o.coachId,`
  + `onChange:u=>{const g=__ekGuru.find(x=>x.uid===u.target.value);l({...o,coachId:u.target.value,coachName:g?g.displayName:""})},`
  + `children:[a.jsx("option",{value:"",children:"— Belum ada pembina —"},"__none"),`
  + `...__ekGuru.map(g=>a.jsx("option",{value:g.uid,children:g.displayName+(g.nip?" ("+g.nip+")":"")},g.uid))]})`
  + `:a.jsx("input",{type:"text",disabled:!0,className:"${INPUT_CLASS} cursor-not-allowed",value:o.coachName})`
  + `,t&&t.coachValid===!1&&t.coachNameLegacy&&a.jsx("p",{className:"text-[10px] font-bold text-amber-600 ml-1",children:"Tercatat sebelumnya: "+t.coachNameLegacy})`;

const LOAD_GURU = 'const __ekAdm=(e==null?void 0:e.role)==="ADMIN",[__ekGuru,__ekSetGuru]=ue.useState([]);'
  + 'ue.useEffect(()=>{__ekAdm&&et.users.list().then(u=>__ekSetGuru((Array.isArray(u)?u:[]).filter(g=>g.role==="GURU")'
  + '.sort((p,q)=>String(p.displayName).localeCompare(String(q.displayName),"id")))).catch(()=>{})},[__ekAdm]);';

const patches = [
  {
    id: 'F1 Menu: item Ekstrakurikuler untuk ADMIN',
    from: '{id:"extracurricular",label:"Ekstrakurikuler",icon:a.jsx(R_,{size:20}),roles:["KEPALA_SEKOLAH","GURU"]}',
    to: '{id:"extracurricular",label:"Ekstrakurikuler",icon:a.jsx(R_,{size:20}),roles:["KEPALA_SEKOLAH","GURU","ADMIN"]}',
  },
  {
    id: 'F2 Sidebar GURU non-BK: item Ekstrakurikuler tampil bila pembina/wali terkait',
    from: 'else if(Y.title==="Kesiswaan"&&!(s!=null&&s.class_homeroom)||Y.title==="Umum & Ekskul")return!1',
    to: `else if(Y.title==="Kesiswaan"&&!(s!=null&&s.class_homeroom)||Y.title==="Umum & Ekskul"&&!(ne.id==="extracurricular"&&(${HAS_EKSKUL})))return!1`,
  },
  {
    id: 'F3 Sidebar GURU BK: item Ekstrakurikuler hanya bila pembina/wali terkait',
    from: 'if(M){if(Y.title==="Pusat Dashboard"||Y.title==="Akademik"||Y.title==="Kesiswaan")return!1}',
    to: `if(M){if(Y.title==="Pusat Dashboard"||Y.title==="Akademik"||Y.title==="Kesiswaan"||ne.id==="extracurricular"&&!(${HAS_EKSKUL}))return!1}`,
  },
  {
    id: 'F4 tot: pengelola penuh hanya ADMIN; daftar ekskul yang boleh dicatat kegiatannya',
    from: 'Y=(e==null?void 0:e.role)==="KEPALA_SEKOLAH"||(e==null?void 0:e.role)==="ADMIN"||(e==null?void 0:e.role)==="GURU"&&H',
    to: 'Y=(e==null?void 0:e.role)==="ADMIN",__ekMan=i.filter(x=>Y||!!(x.access&&x.access.canManageActivities))',
  },
  {
    id: 'F5 tot kartu Daftar Ekskul: edit untuk canEditInfo, hapus hanya ADMIN',
    from: 'Y?a.jsxs("div",{className:"flex gap-2",children:[a.jsx("button",{onClick:ie=>{ie.stopPropagation(),N(X),b(!0)},className:"p-2 text-blue-300 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all",title:"Edit Ekskul",children:a.jsx(G1,{size:18})}),a.jsx("button",{onClick:ie=>K(X.id,ie)',
    to: 'Y||X.access&&X.access.canEditInfo?a.jsxs("div",{className:"flex gap-2",children:[a.jsx("button",{onClick:ie=>{ie.stopPropagation(),N(X),b(!0)},className:"p-2 text-blue-300 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all",title:"Edit Ekskul",children:a.jsx(G1,{size:18})}),Y&&a.jsx("button",{onClick:ie=>K(X.id,ie)',
  },
  {
    id: 'F6 tot tombol Input Kegiatan: hanya bila ada ekskul yang boleh dikelola',
    from: '((e==null?void 0:e.role)==="GURU"||(e==null?void 0:e.role)==="KEPALA_SEKOLAH"||(e==null?void 0:e.role)==="ADMIN")&&a.jsxs("button",{onClick:()=>{t==="LIST"&&Y?b(!0):i.length===1?(z(i[0]),L("ACTIVITIES"),R(!0)):h(!0)}',
    to: '(Y||__ekMan.length>0)&&a.jsxs("button",{onClick:()=>{t==="LIST"&&Y?b(!0):__ekMan.length===1?(z(__ekMan[0]),L("ACTIVITIES"),R(!0)):h(!0)}',
  },
  {
    id: 'F6b tot picker rot: hanya ekskul yang boleh dikelola',
    from: 'a.jsx(rot,{ekskuls:i,',
    to: 'a.jsx(rot,{ekskuls:__ekMan,',
  },
  {
    id: 'F7 tot kartu jurnal: aksi edit/hapus mengikuti canManage',
    from: '((e==null?void 0:e.role)==="GURU"||(e==null?void 0:e.role)==="ADMIN"||(e==null?void 0:e.role)==="KEPALA_SEKOLAH")&&a.jsxs("div",{className:"flex items-center gap-2 mt-4"',
    to: 'X.canManage===!0&&a.jsxs("div",{className:"flex items-center gap-2 mt-4"',
  },
  {
    id: 'F7b tot kartu jurnal: presensi kosong (wali tanpa siswa di pertemuan) tampil "--"',
    from: 'children:[X.attendanceRate,"%"]',
    to: 'children:[X.attendanceRate==null?"--":X.attendanceRate,"%"]',
  },
  {
    id: 'F8a not: pembina lama yang tidak valid dimulai kosong',
    from: 'coachId:(t==null?void 0:t.coachId)||""',
    to: 'coachId:t&&t.coachValid!==!1&&t.coachId||""',
  },
  {
    id: 'F8b not: muat daftar akun GURU (khusus ADMIN)',
    from: 'e!=null&&e.schoolId;const c=async u=>{u.preventDefault(),i(!0);try{await et.extracurriculars.create(',
    to: `e!=null&&e.schoolId;${LOAD_GURU}const c=async u=>{u.preventDefault(),i(!0);try{await et.extracurriculars.create(`,
  },
  {
    id: 'F8c not: nama ekskul read-only untuk non-ADMIN',
    from: 'value:o.name,onChange:u=>l({...o,name:u.target.value})',
    to: 'value:o.name,disabled:!__ekAdm,onChange:u=>l({...o,name:u.target.value})',
  },
  {
    id: 'F8d not: input teks pembina diganti dropdown akun GURU',
    from: `a.jsx("input",{type:"text",required:!0,placeholder:"Nama Guru Pembina",className:"${INPUT_CLASS}",value:o.coachName,onChange:u=>l({...o,coachName:u.target.value})})`,
    to: COACH_FIELD,
  },
  {
    id: 'F8e not: tampilkan pesan error backend saat simpan gagal',
    from: 'console.error("Create ekskul error:",h)',
    to: 'console.error("Create ekskul error:",h),alert("Gagal menyimpan ekskul: "+(()=>{try{return JSON.parse(h.message).error}catch{return h&&h.message}})())',
  },
  {
    id: 'F9 not submit: coachId tanpa fallback uid pembuat',
    from: 'coachName:o.coachName,coachId:o.coachId||e.uid',
    to: 'coachName:o.coachName,coachId:o.coachId',
  },
  {
    id: 'F10a sot: flag izin per ekskul',
    from: 'function sot({ekskul:e,user:t,onBack:n,initialTab:r="INFO",autoOpenActivity:s=!1,initialEditingActivityId:i=null}){',
    to: 'function sot({ekskul:e,user:t,onBack:n,initialTab:r="INFO",autoOpenActivity:s=!1,initialEditingActivityId:i=null}){const __ekMem=!!(e&&e.access&&e.access.canManageMembers),__ekAct=!!(e&&e.access&&e.access.canManageActivities);',
  },
  {
    id: 'F10b sot: tombol Tambah Anggota',
    from: 'children:"Daftar Anggota Ekskul"}),a.jsxs("button",{onClick:()=>A(!0)',
    to: 'children:"Daftar Anggota Ekskul"}),__ekMem&&a.jsxs("button",{onClick:()=>A(!0)',
  },
  {
    id: 'F11 sot: tombol keluarkan anggota',
    from: 'children:a.jsx("button",{onClick:()=>Q(oe.student_id)',
    to: 'children:__ekMem&&a.jsx("button",{onClick:()=>Q(oe.student_id)',
  },
  {
    id: 'F12 sot: aksi edit/hapus aktivitas',
    from: '((t==null?void 0:t.role)==="GURU"||(t==null?void 0:t.role)==="ADMIN"||(t==null?void 0:t.role)==="KEPALA_SEKOLAH")',
    to: '__ekAct',
  },
  {
    id: 'F13 sot: tombol Catat Aktivitas',
    from: 'children:"Histori Kegiatan"}),a.jsxs("button",{onClick:()=>H(!0)',
    to: 'children:"Histori Kegiatan"}),__ekAct&&a.jsxs("button",{onClick:()=>H(!0)',
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
