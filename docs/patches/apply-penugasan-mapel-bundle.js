// Patch bundle frontend: penugasan mata pelajaran mandiri oleh guru (halaman Profil).
// Spesifikasi: docs/specs/penugasan-mapel-mandiri-guru.md
// Prasyarat: patch wali kelas, rekap bulanan, pembina ekskul, dan perangkat belajar sudah
// diterapkan (sumber = -perangkat1.js).
// Pemakaian (dari root deploy): node docs/patches/apply-penugasan-mapel-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-perangkat1.js';
const OUT_NAME = 'index-CGWL69P7-mapel1.js';
const SRC_SHA256 = '518c88dbd27e812779a661bdadfff87cdf3c858f6a94de0619d05dbf9d972537';

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
if (src.includes('__tm')) fail('prefiks identifier __tm sudah dipakai di bundle sumber.');

// Kelas Tailwind yang dipakai ulang dari gaya modal Admin.
const LABEL_CLS = 'text-[9px] font-black text-slate-400 uppercase tracking-wider block mb-1';
const SELECT_CLS = 'w-full px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-bold outline-none bg-white cursor-pointer focus:ring-2 focus:ring-blue-200';

// --- F1..F7: state, pemuatan data, dan handler di komponen Profil (uot) ---
const UOT_STATE = 'const __tmRole=(e==null?void 0:e.role)||"",__tmGuru=__tmRole==="GURU",__tmUid=(e==null?void 0:e.uid)||"";'
  + 'const[__tmRows,__tmSetRows]=ue.useState([]),[__tmSaved,__tmSetSaved]=ue.useState([]),[__tmSubs,__tmSetSubs]=ue.useState([]),'
  + '[__tmCls,__tmSetCls]=ue.useState([]),[__tmBusy,__tmSetBusy]=ue.useState(!0),[__tmLoadErr,__tmSetLoadErr]=ue.useState(""),'
  + '[__tmMsg,__tmSetMsg]=ue.useState(null),[__tmSaving,__tmSetSaving]=ue.useState(!1),[__tmSub,__tmSetSub]=ue.useState(""),'
  + '[__tmLvl,__tmSetLvl]=ue.useState(""),[__tmPick,__tmSetPick]=ue.useState([]),[__tmFormErr,__tmSetFormErr]=ue.useState(""),'
  + '[__tmDel,__tmSetDel]=ue.useState(-1);'
  // Pesan error: backend mengirim {"error":"..."} dan yt() melempar Error berisi body mentah.
  + 'const __tmApiErr=__tmX=>{try{return JSON.parse(__tmX.message).error||__tmX.message}catch{return __tmX&&__tmX.message||"Periksa koneksi jaringan Anda."}};'
  + 'const __tmNorm=__tmX=>({subject_code:String(__tmX.subject_code||__tmX.code||""),subject_name:String(__tmX.subject_name||__tmX.name||""),'
  + 'class_level:String(__tmX.class_level||""),class_ids:(Array.isArray(__tmX.class_ids)?__tmX.class_ids:JSON.parse(__tmX.class_ids||"[]")||[]).map(String)});'
  + 'const __tmKey=__tmX=>JSON.stringify([__tmX.subject_code,__tmX.subject_name,__tmX.class_level,[...__tmX.class_ids].sort()]);'
  + 'const __tmSig=__tmL=>JSON.stringify(__tmL.map(__tmKey));'
  + 'const __tmFetch=async()=>{__tmSetBusy(!0);__tmSetLoadErr("");try{'
  + 'const __tmR=await Promise.all([et.teacherSubjects.get(__tmUid),'
  + 'fetch(nr+"?action=get_subjects&active_only=1").then(__tmX=>__tmX.ok?__tmX.json():[]),'
  + 'fetch(nr+"?action=get_classes").then(__tmX=>__tmX.ok?__tmX.json():[])]);'
  + 'const __tmL=(Array.isArray(__tmR[0])?__tmR[0]:[]).map(__tmNorm);__tmSetRows(__tmL);__tmSetSaved(__tmL);'
  + '__tmSetSubs(Array.isArray(__tmR[1])?__tmR[1]:[]);__tmSetCls(Array.isArray(__tmR[2])?__tmR[2]:[])'
  + '}catch(__tmE){__tmSetLoadErr(__tmApiErr(__tmE))}finally{__tmSetBusy(!1)}};'
  + 'ue.useEffect(()=>{__tmGuru&&__tmFetch()},[__tmGuru,__tmUid]);'
  + 'const __tmLevels=(()=>{const __tmS=[...new Set(__tmCls.map(__tmC=>String(__tmC.level||"").trim()).filter(Boolean))];'
  + 'return __tmS.length?__tmS.sort((__tmP,__tmQ)=>__tmP.length-__tmQ.length||__tmP.localeCompare(__tmQ)):["X","XI","XII"]})();'
  + 'const __tmClsOf=__tmLv=>__tmCls.filter(__tmC=>String(__tmC.level||"").trim().toLowerCase()===String(__tmLv).trim().toLowerCase());'
  + 'const __tmDirty=__tmSig(__tmRows)!==__tmSig(__tmSaved);'
  // Tanda "perlu dilengkapi" untuk data warisan yang tidak memenuhi aturan baru.
  + 'const __tmWarn=__tmIt=>{if(!__tmIt.class_level)return"level kelas belum diisi";'
  + 'if(!__tmIt.class_ids.length)return"kelas belum dipilih";'
  + 'if(__tmSubs.length&&!__tmSubs.some(__tmS=>String(__tmS.id).toLowerCase()===__tmIt.subject_code.toLowerCase()))return"mapel tidak aktif di master";'
  + 'const __tmMiss=__tmCls.length?__tmIt.class_ids.filter(__tmC=>!__tmCls.some(__tmK=>String(__tmK.id).toLowerCase()===String(__tmC).toLowerCase())):[];'
  + 'return __tmMiss.length?"kelas "+__tmMiss.join(", ")+" tidak ditemukan":""};'
  + 'const __tmAdd=()=>{__tmSetFormErr("");const __tmS=__tmSubs.find(__tmX=>String(__tmX.id)===__tmSub);'
  + 'if(!__tmS||!__tmLvl||!__tmPick.length)return;'
  + 'if(__tmRows.some(__tmX=>__tmX.subject_code.toLowerCase()===String(__tmS.id).toLowerCase()&&__tmX.class_level.toLowerCase()===__tmLvl.toLowerCase()))'
  + '{__tmSetFormErr("Mapel dengan level kelas yang sama sudah ada. Hapus yang lama atau gabungkan kelasnya.");return}'
  + '__tmSetRows(__tmX=>[...__tmX,{subject_code:String(__tmS.id),subject_name:String(__tmS.name),class_level:__tmLvl,class_ids:[...__tmPick]}]);'
  + '__tmSetSub("");__tmSetLvl("");__tmSetPick([]);__tmSetMsg(null)};'
  + 'const __tmRemove=__tmI=>{__tmSetRows(__tmX=>__tmX.filter((__tmY,__tmJ)=>__tmJ!==__tmI));__tmSetDel(-1);__tmSetMsg(null)};'
  + 'const __tmSave=async()=>{__tmSetSaving(!0);__tmSetMsg(null);try{const __tmR=await et.teacherSubjects.save(__tmUid,__tmRows);'
  + 'if(__tmR&&Array.isArray(__tmR.items)){const __tmL=__tmR.items.map(__tmNorm);__tmSetRows(__tmL);__tmSetSaved(__tmL)}else await __tmFetch();'
  + '__tmSetMsg({type:"ok",text:"Penugasan mata pelajaran tersimpan"})}'
  + 'catch(__tmE){__tmSetMsg({type:"err",text:__tmApiErr(__tmE)})}finally{__tmSetSaving(!1)}};';

// Kartu "Penugasan Mata Pelajaran" (hanya untuk GURU), disisipkan sebelum kartu Ubah Password.
const TM_FORM = 'a.jsxs("div",{className:"bg-slate-50 rounded-2xl p-5 space-y-4 border border-slate-100",children:['
  + 'a.jsx("p",{className:"text-[10px] font-black text-slate-400 uppercase tracking-widest",children:"Tambah Penugasan Baru"}),'
  + 'a.jsxs("div",{className:"grid grid-cols-1 sm:grid-cols-2 gap-3",children:['
  + `a.jsxs("div",{children:[a.jsx("label",{className:"${LABEL_CLS}",children:"Mata Pelajaran"}),`
  + `a.jsxs("select",{value:__tmSub,onChange:__tmX=>__tmSetSub(__tmX.target.value),className:"${SELECT_CLS}",children:[`
  + 'a.jsx("option",{value:"",children:"— Pilih Mapel —"}),'
  + '...__tmSubs.map(__tmX=>a.jsx("option",{value:String(__tmX.id),children:__tmX.name},String(__tmX.id)))]})]}),'
  + `a.jsxs("div",{children:[a.jsx("label",{className:"${LABEL_CLS}",children:"Level Kelas"}),`
  + `a.jsxs("select",{value:__tmLvl,onChange:__tmX=>{__tmSetLvl(__tmX.target.value);__tmSetPick([])},className:"${SELECT_CLS}",children:[`
  + 'a.jsx("option",{value:"",children:"— Pilih Level —"}),'
  + '...__tmLevels.map(__tmX=>a.jsx("option",{value:__tmX,children:"Kelas "+__tmX},__tmX))]})]})]}),'
  + '__tmLvl&&a.jsxs("div",{children:[a.jsx("label",{className:"text-[9px] font-black text-slate-400 uppercase tracking-wider block mb-2",'
  + 'children:"Kelas yang Diampu (wajib pilih minimal satu)"}),'
  + '__tmClsOf(__tmLvl).length?a.jsx("div",{className:"grid grid-cols-3 sm:grid-cols-4 gap-2",children:__tmClsOf(__tmLvl).map(__tmC=>{'
  + 'const __tmOn=__tmPick.includes(String(__tmC.id));'
  + 'return a.jsxs("button",{type:"button",onClick:()=>__tmSetPick(__tmP=>__tmP.includes(String(__tmC.id))?__tmP.filter(__tmQ=>__tmQ!==String(__tmC.id)):[...__tmP,String(__tmC.id)]),'
  + 'className:$e("flex items-center gap-1.5 px-3 py-2 rounded-xl border text-xs font-bold transition-all cursor-pointer",'
  + '__tmOn?"bg-blue-600 text-white border-blue-600":"bg-white text-slate-600 border-slate-200 hover:border-blue-300"),'
  + 'children:[__tmOn?a.jsx(Due,{size:12}):a.jsx(vW,{size:12}),String(__tmC.id)]},String(__tmC.id))})}):'
  + 'a.jsx("p",{className:"text-xs text-slate-400 font-bold",children:"Belum ada kelas pada level ini."})]}),'
  + '__tmFormErr&&a.jsxs("div",{className:"flex items-center gap-2 text-xs font-bold text-red-600",children:[a.jsx(Qu,{size:14}),__tmFormErr]}),'
  + 'a.jsxs("button",{onClick:__tmAdd,disabled:!__tmSub||!__tmLvl||!__tmPick.length,'
  + 'className:"w-full py-2.5 bg-blue-600 text-white rounded-xl text-xs font-bold hover:bg-blue-700 transition-all disabled:opacity-40 flex items-center justify-center gap-2 cursor-pointer",'
  + 'children:[a.jsx(es,{size:16})," Tambahkan ke Daftar"]})]})';

const TM_LIST = 'a.jsx("div",{className:"space-y-2",children:__tmRows.length===0?'
  + 'a.jsxs("div",{className:"text-center py-10 text-slate-400 text-xs font-bold uppercase tracking-widest border-2 border-dashed border-slate-100 rounded-2xl",'
  + 'children:[a.jsx(z2,{size:32,className:"mx-auto mb-2 opacity-30"}),"Belum ada mapel ditugaskan"]}):'
  + '__tmRows.map((__tmIt,__tmI)=>{const __tmW=__tmWarn(__tmIt);'
  + 'return a.jsxs("div",{className:"flex items-start justify-between p-4 bg-slate-50 border border-slate-100 rounded-2xl gap-3",children:['
  + 'a.jsxs("div",{className:"flex-1 min-w-0",children:[a.jsxs("div",{className:"flex items-center gap-2 flex-wrap",children:['
  + 'a.jsx("span",{className:"px-2 py-0.5 bg-white border border-slate-200 rounded-lg text-[10px] font-black uppercase text-slate-500",children:__tmIt.subject_code}),'
  + 'a.jsx("span",{className:"font-bold text-slate-800 text-sm",children:__tmIt.subject_name||__tmIt.subject_code}),'
  + '__tmIt.class_level&&a.jsxs("span",{className:"px-2 py-0.5 bg-blue-50 text-blue-600 rounded-lg text-[10px] font-bold",children:["Kelas ",__tmIt.class_level]})]}),'
  + '__tmIt.class_ids.length>0&&a.jsx("div",{className:"flex flex-wrap gap-1 mt-2",children:__tmIt.class_ids.map(__tmC=>'
  + 'a.jsx("span",{className:"px-2 py-0.5 bg-blue-50 text-blue-600 rounded-md text-[10px] font-bold",children:__tmC},__tmC))}),'
  + '__tmW&&a.jsxs("div",{className:"flex items-center gap-1.5 mt-2 text-[10px] font-black uppercase tracking-wider text-amber-600",'
  + 'children:[a.jsx(Z1,{size:12}),"Perlu dilengkapi — "+__tmW]})]}),'
  + '__tmDel===__tmI?a.jsxs("div",{className:"flex items-center gap-2 shrink-0",children:['
  + 'a.jsx("button",{onClick:()=>__tmRemove(__tmI),className:"px-3 py-1.5 rounded-lg bg-red-600 text-white text-[10px] font-bold cursor-pointer",children:"Hapus"}),'
  + 'a.jsx("button",{onClick:()=>__tmSetDel(-1),className:"px-3 py-1.5 rounded-lg border border-slate-200 text-[10px] font-bold text-slate-500 cursor-pointer",children:"Batal"})]}):'
  + 'a.jsx("button",{onClick:()=>__tmSetDel(__tmI),title:"Hapus penugasan",className:"text-slate-300 hover:text-red-500 transition-colors shrink-0 cursor-pointer",children:a.jsx(ar,{size:16})})]},'
  + '__tmIt.subject_code+"#"+__tmIt.class_level+"#"+__tmI)})})';

const TM_SAVE = 'a.jsxs("div",{className:"flex items-center gap-3 flex-wrap pt-1",children:['
  + 'a.jsxs("button",{onClick:__tmSave,disabled:__tmSaving||!__tmDirty,'
  + 'className:"flex items-center gap-2 px-5 py-2.5 bg-blue-600 text-white rounded-xl font-bold text-sm hover:bg-blue-700 disabled:opacity-40 transition-all shadow-lg shadow-blue-100 cursor-pointer",'
  + 'children:[__tmSaving?a.jsx(y1,{size:14,className:"animate-spin"}):a.jsx(s3,{size:14}),"Simpan Penugasan"]}),'
  + '__tmDirty&&!__tmSaving&&a.jsx("span",{className:"text-xs font-bold text-amber-600",children:"Ada perubahan yang belum disimpan"})]}),'
  + '__tmMsg&&a.jsxs("div",{className:"flex items-center gap-2 p-3 rounded-xl text-sm font-medium "+(__tmMsg.type==="ok"?'
  + '"bg-emerald-50 text-emerald-700 border border-emerald-200":"bg-red-50 text-red-700 border border-red-200"),'
  + 'children:[__tmMsg.type==="ok"?a.jsx(cr,{size:16}):a.jsx(Qu,{size:16}),__tmMsg.text]})';

const TM_CARD = '__tmGuru&&a.jsxs(H$,{title:"Penugasan Mata Pelajaran",icon:a.jsx(lc,{size:16}),children:['
  + 'a.jsx("p",{className:"text-xs text-slate-500 font-medium mb-4",'
  + 'children:"Penugasan ini menentukan mapel dan kelas yang muncul di Jurnal Mengajar dan Nilai. Perubahan langsung berlaku."}),'
  + '__tmBusy?a.jsxs("div",{className:"flex items-center justify-center gap-2 text-slate-400 text-sm font-bold py-6",'
  + 'children:[a.jsx(y1,{size:16,className:"animate-spin"}),"Memuat penugasan…"]}):'
  + '__tmLoadErr?a.jsxs("div",{className:"space-y-3",children:['
  + 'a.jsxs("div",{className:"flex items-center gap-2 p-3 rounded-xl text-sm font-medium bg-red-50 text-red-700 border border-red-200",'
  + 'children:[a.jsx(Qu,{size:16}),__tmLoadErr]}),'
  + 'a.jsx("button",{onClick:__tmFetch,className:"px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 cursor-pointer",children:"Coba lagi"})]}):'
  + 'a.jsxs("div",{className:"space-y-5",children:[' + TM_FORM + ',' + TM_LIST + ',' + TM_SAVE + ']})]}),';

// F8: modal edit user Admin menampilkan pesan error dari backend.
const ADMIN_ALERT = 'alert((()=>{try{return JSON.parse(Pe.message).error||Pe.message}catch{return "Gagal menyimpan. Cek koneksi server."}})())';

const patches = [
  {
    id: 'F1-F7a uot: state, pemuatan data, dan handler penugasan mapel',
    from: 'function uot({user:e}){',
    to: 'function uot({user:e}){' + UOT_STATE,
  },
  {
    id: 'F1-F7b uot: kartu Penugasan Mata Pelajaran sebelum kartu Ubah Password',
    from: 'a.jsxs(H$,{title:"Ubah Password"',
    to: TM_CARD + 'a.jsxs(H$,{title:"Ubah Password"',
  },
  {
    id: 'F8 modal edit user Admin: tampilkan pesan error backend',
    from: 'alert("Gagal menyimpan. Cek koneksi server.")',
    to: ADMIN_ALERT,
  },
];

let out = src;
for (const p of patches) {
  const expected = p.count || 1;
  const found = out.split(p.from).length - 1;
  if (found !== expected) fail(`anchor tidak cocok untuk "${p.id}": ditemukan ${found}, diharapkan ${expected}.`);
  out = out.split(p.from).join(p.to);
  console.log(`OK  ${p.id}`);
}

if (out === src) fail('tidak ada perubahan yang diterapkan.');

fs.writeFileSync(outPath, out);
console.log(`\nDitulis: assets/${OUT_NAME} (${out.length} byte)`);
console.log(`SHA-256 hasil: ${crypto.createHash('sha256').update(fs.readFileSync(outPath)).digest('hex')}`);

const appHtml = fs.readFileSync(appHtmlPath, 'utf8');
if (!appHtml.includes(SRC_NAME)) fail(`app.html tidak mereferensikan ${SRC_NAME}; periksa manual.`);
fs.writeFileSync(appHtmlPath, appHtml.split(SRC_NAME).join(OUT_NAME));
console.log(`app.html diarahkan ke ${OUT_NAME}`);
