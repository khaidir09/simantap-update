// Patch bundle frontend: halaman Kelola Guru (komponen `lot`, menu teacher-management).
//  K1 Daftar guru menjadi tabel lebar penuh (>= md) / kartu (< md); panel edit samping dihapus.
//     Kolom: Pengguna (badge role/Wali/BK), Email/NIP, Mapel, Jadwal KBM, Login Terakhir, Aksi.
//     Cari nama/email/NIP, filter role (+ TATA USAHA), filter Belum lengkap / Tidak aktif >30 hari,
//     urutkan nama / login terbaru / login terlama.
//  K2 Edit data lewat modal (X, Esc, klik backdrop, Batal; konfirmasi bila ada perubahan).
//  K3 Field Email Login di modal, hanya bisa diubah ADMIN (backend juga menegakkan, lihat
//     api/kelola_guru.php). KEPALA_SEKOLAH melihat modal read-only.
//  K4 Status penugasan mapel (mapel_count), jadwal KBM (jadwal_count), dan login terakhir
//     (last_login_at / last_login_hari) dibaca dari get_users.
// Spesifikasi: docs/specs/kelola-guru-tabel-modal.md
// Pemakaian (dari root deploy): node docs/patches/apply-kelola-guru-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-badgesanksi.js';
const OUT_NAME = 'index-CGWL69P7-kelolaguru.js';
const SRC_SHA256 = 'b5e493a6178ebba2b297cfc97f77f30e6d8b8b9af0e2508cbbc03bd96195bf95';

const srcPath = path.join(ROOT, 'assets', SRC_NAME);
const outPath = path.join(ROOT, 'assets', OUT_NAME);
const appHtmlPath = path.join(ROOT, 'app.html');

function fail(msg) {
  console.error('GAGAL: ' + msg);
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Komponen pengganti. TIDAK dijalankan di Node: sumbernya disisipkan ke bundle via toString().
// Identifier bundle yang dipakai: ue (React), a (jsx runtime), $e (clsx), et (API), U$ (URL API),
// zi (header halaman), Ci (avatar), Si/Pt (framer-motion), dan ikon lucide:
// Ui search, s3 save, J1 shield, X1, Rce, Ule, lc book-open, Due, vW, es, z2, pi x, t0,
// zp mail, Z1 triangle-alert, fue pencil, O_ eye, wf calendar-days, Vo clock.
// Hanya kelas Tailwind yang ada di assets/index-CC1PBzaO.css (dicek di bawah).
// ---------------------------------------------------------------------------
function lot({user:e}){
  const isAdmin=(e==null?void 0:e.role)==="ADMIN";
  const[t,n]=ue.useState([]),[r,s]=ue.useState(!0),[i,o]=ue.useState(!1),[l,c]=ue.useState(""),[u,h]=ue.useState("ALL"),
    [kgStatus,kgSetStatus]=ue.useState("ALL"),[kgSort,kgSetSort]=ue.useState("NAMA"),
    [p,b]=ue.useState(null),[y,N]=ue.useState(""),[A,E]=ue.useState(""),[T,R]=ue.useState(0),[P,L]=ue.useState([]),
    [U,I]=ue.useState(""),[M,z]=ue.useState(!1),[H,Y]=ue.useState(""),[ne,K]=ue.useState(""),[me,de]=ue.useState([]),
    [X,ie]=ue.useState([]),[le,Ee]=ue.useState([]),
    [kgEmail,kgSetEmail]=ue.useState(""),[kgErr,kgSetErr]=ue.useState(""),[kgAwal,kgSetAwal]=ue.useState(null),[kgMuat,kgSetMuat]=ue.useState(!1);
  const ke=ue.useMemo(()=>ne?le.filter(Pe=>Pe.level===ne):[],[le,ne]),
    Ce=ue.useMemo(()=>{const Pe={};return le.forEach(Z=>{Pe[Z.level]||(Pe[Z.level]=[]),Pe[Z.level].push(Z)}),Pe},[le]);
  const Q=async()=>{s(!0);try{const Pe=await et.users.list();n(Array.isArray(Pe)?Pe:[])}catch(Pe){console.error(Pe)}s(!1)};
  const G=async()=>{try{const[Pe,Z]=await Promise.all([fetch(U$+"?action=get_subjects&active_only=1"),fetch(U$+"?action=get_classes")]);Pe.ok&&ie(await Pe.json()),Z.ok&&Ee(await Z.json())}catch(Pe){console.error(Pe)}};
  ue.useEffect(()=>{Q(),G()},[]);

  const kgPesan=(Pe,Z)=>{try{return JSON.parse(Pe.message).error||Z}catch{return Z}};
  const kgNorm=Pe=>String(Pe||"").trim().toLowerCase();
  const kgEmailBaru=kgNorm(kgEmail);
  const kgEmailValid=/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(kgEmailBaru);
  const kgEmailBerubah=!!p&&kgEmailBaru!==kgNorm(p.email);
  const kgSnap=()=>JSON.stringify({role:y,wali:A,bk:T,email:kgEmailBaru,mapel:P});
  const kgDirty=!!p&&!!kgAwal&&(kgSnap()!==kgAwal||(M&&U!==""));
  const kgBelumLengkap=Pe=>Pe.role==="GURU"&&(!(Pe.mapel_count>0)||!(Pe.jadwal_count>0));
  const kgNonaktif=Pe=>Pe.last_login_hari==null||Pe.last_login_hari>30;
  const kgNamaKelas=Pe=>{const Z=le.find(ht=>ht.id===Pe);return Z&&Z.name||Pe};
  const kgRoleCls={GURU:"bg-blue-50 text-blue-600",KEPALA_SEKOLAH:"bg-blue-50 text-blue-700",ADMIN:"bg-red-50 text-red-600",TATA_USAHA:"bg-amber-50 text-amber-700"};
  const kgBadgeCls="text-[10px] font-black px-2 py-1 rounded-lg whitespace-nowrap";

  // Buka modal: isi form dari baris, lalu muat penugasan mapel guru tersebut
  const ge=async Pe=>{
    b(Pe),N(Pe.role||"GURU"),E(Pe.class_homeroom||""),R(Pe.is_bk?1:0),I(""),z(!1),Y(""),K(""),de([]),L([]),
    kgSetEmail(Pe.email||""),kgSetErr(""),kgSetAwal(null),kgSetMuat(!0);
    let Z=[];
    try{const ht=await et.teacherSubjects.get(Pe.uid);Z=(Array.isArray(ht)?ht:[]).map(it=>({subject_code:it.subject_code||it.code||"",subject_name:it.subject_name||it.name||"",class_level:it.class_level||"",class_ids:Array.isArray(it.class_ids)?it.class_ids:JSON.parse(it.class_ids||"[]")}))}catch{Z=[]}
    L(Z),kgSetMuat(!1),
    kgSetAwal(JSON.stringify({role:Pe.role||"GURU",wali:Pe.class_homeroom||"",bk:Pe.is_bk?1:0,email:kgNorm(Pe.email),mapel:Z}));
  };
  const kgTutup=Pe=>{if(!Pe&&kgDirty&&!confirm("Buang perubahan yang belum disimpan?"))return;b(null),kgSetErr("")};
  const kgTutupRef=ue.useRef(null);kgTutupRef.current=kgTutup;
  ue.useEffect(()=>{if(!p)return;const Pe=Z=>{Z.key==="Escape"&&!i&&kgTutupRef.current&&kgTutupRef.current()};return window.addEventListener("keydown",Pe),()=>window.removeEventListener("keydown",Pe)},[p,i]);

  const ce=Pe=>{de(Z=>Z.includes(Pe)?Z.filter(ht=>ht!==Pe):[...Z,Pe])};
  const oe=()=>{if(!H)return;const Pe=X.find(Z=>Z.id===H);if(Pe){if(P.some(Z=>Z.subject_code===Pe.id&&Z.class_level===ne)){alert("Mapel dengan level kelas yang sama sudah ada. Hapus yang lama terlebih dahulu.");return}L(Z=>[...Z,{subject_code:Pe.id,subject_name:Pe.name,class_level:ne,class_ids:me}]),Y(""),K(""),de([])}};
  const be=Pe=>{L(Z=>Z.filter((ht,it)=>it!==Pe))};

  // Simpan: profil (save_user_v2) dulu; bila gagal tidak ada yang tersimpan. Lalu penugasan mapel.
  const we=async()=>{
    if(!p||!isAdmin||i||kgMuat)return;
    if(!kgEmailValid){kgSetErr("Format email tidak valid.");return}
    o(!0),kgSetErr("");
    const Pe={uid:p.uid,email:kgEmailBaru,displayName:p.displayName,nip:p.nip||"",schoolName:p.schoolName||"SMAN 1 BANJARBARU",role:y,class_homeroom:A,is_bk:T};
    M&&U&&(Pe.password=U);
    try{await et.users.save(Pe)}catch(Z){console.error(Z),kgSetErr(kgPesan(Z,"Gagal menyimpan profil. Cek koneksi server.")),o(!1);return}
    const Z={...p,email:kgEmailBaru,role:y,class_homeroom:A,is_bk:T};
    try{await et.teacherSubjects.save(p.uid,P)}catch(ht){
      console.error(ht),b(Z),I(""),
      kgSetAwal(JSON.stringify({role:y,wali:A,bk:T,email:kgEmailBaru,mapel:JSON.parse(kgAwal||"{}").mapel||[]})),
      kgSetErr("Profil tersimpan, tetapi penugasan mapel gagal disimpan: "+kgPesan(ht,"cek koneksi server.")),o(!1),Q();return}
    o(!1),b(null),Q(),alert("Profil & penugasan guru berhasil disimpan!");
  };

  const kgLogin=Pe=>{
    const Z=Pe.last_login_hari;
    const ht=Pe.last_login_at?"Login terakhir: "+(()=>{const it=new Date(String(Pe.last_login_at).replace(" ","T"));return isNaN(it)?Pe.last_login_at:it.toLocaleString("id-ID",{dateStyle:"medium",timeStyle:"short"})})():"Belum ada login yang tercatat sejak fitur ini dipasang";
    if(Z==null)return{label:"Belum tercatat",dot:"bg-slate-300",cls:"text-slate-400",title:ht};
    const it=Z===0?"Hari ini":Z===1?"Kemarin":Z+" hari lalu";
    return Z<=7?{label:it,dot:"bg-emerald-500",cls:"text-emerald-700",title:ht}:Z<=30?{label:it,dot:"bg-amber-500",cls:"text-amber-700",title:ht}:{label:it,dot:"bg-slate-300",cls:"text-slate-500",title:ht};
  };
  const kgBadgeMapel=Pe=>Pe.role!=="GURU"?a.jsx("span",{className:"text-slate-300 font-bold",children:"—"}):Pe.mapel_count>0?a.jsx("span",{className:$e(kgBadgeCls,"bg-emerald-50 text-emerald-700"),children:Pe.mapel_count+" mapel"}):a.jsx("span",{className:$e(kgBadgeCls,"bg-red-50 text-red-600"),children:"Belum ada"});
  const kgBadgeJadwal=Pe=>Pe.role!=="GURU"?a.jsx("span",{className:"text-slate-300 font-bold",children:"—"}):Pe.jadwal_count==null?a.jsx("span",{className:$e(kgBadgeCls,"bg-amber-50 text-amber-700"),title:"Data jadwal KBM tidak dapat dibaca",children:"Tidak terbaca"}):Pe.jadwal_count>0?a.jsx("span",{className:$e(kgBadgeCls,"bg-emerald-50 text-emerald-700"),children:Pe.jadwal_count+" sesi"}):a.jsx("span",{className:$e(kgBadgeCls,"bg-red-50 text-red-600"),children:"Belum ada"});
  const kgBadgeLogin=Pe=>{const Z=kgLogin(Pe);return a.jsxs("span",{className:$e("inline-flex items-center gap-1.5 text-xs font-bold whitespace-nowrap",Z.cls),title:Z.title,children:[a.jsx("span",{className:$e("w-2 h-2 rounded-full shrink-0",Z.dot)}),Z.label]})};
  const kgBadgeRole=Pe=>a.jsxs("div",{className:"flex items-center gap-1.5 mt-0.5 flex-wrap",children:[
    a.jsx("span",{className:$e("text-[9px] font-black tracking-widest uppercase px-1.5 py-0.5 rounded-md",kgRoleCls[Pe.role]||"bg-slate-100 text-slate-500"),children:String(Pe.role||"").replace("_"," ")}),
    Pe.class_homeroom&&a.jsxs("span",{className:"text-[9px] font-bold text-slate-400 truncate",children:["Wali ",kgNamaKelas(Pe.class_homeroom)]}),
    Number(Pe.is_bk)===1&&a.jsx("span",{className:"text-[8px] font-black px-1.5 py-0.5 rounded-md bg-blue-100 text-blue-700 border border-blue-200",children:"BK"})]});

  const kgQ=l.trim().toLowerCase();
  const Ae=t.filter(Pe=>{
    const Z=!kgQ||[Pe.displayName,Pe.email,Pe.nip].some(ht=>String(ht||"").toLowerCase().includes(kgQ));
    const ht=u==="ALL"||Pe.role===u;
    const it=kgStatus==="ALL"||(kgStatus==="BELUM"?kgBelumLengkap(Pe):kgNonaktif(Pe));
    return Z&&ht&&it;
  }).sort((Pe,Z)=>{
    if(kgSort!=="NAMA"){const ht=Pe.last_login_at||"",it=Z.last_login_at||"";if(ht!==it){if(!ht)return 1;if(!it)return-1;return kgSort==="LOGIN_BARU"?(ht<it?1:-1):(ht<it?-1:1)}}
    return String(Pe.displayName||"").localeCompare(String(Z.displayName||""),"id");
  });
  const kgJmlBelum=t.filter(kgBelumLengkap).length;
  const kgChip=(Pe,Z,ht,it)=>a.jsx("button",{type:"button",onClick:()=>ht(Pe),className:$e("px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider whitespace-nowrap transition-all border cursor-pointer",it===Pe?"bg-blue-600 text-white border-blue-600":"bg-white text-slate-500 border-slate-100 hover:border-blue-200"),children:Z},Pe);
  const kgInputCls="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-blue-300 disabled:opacity-60";

  return a.jsxs("div",{className:"max-w-7xl mx-auto space-y-6 pb-20",children:[
    a.jsx(zi,{title:"Manajemen Guru",description:"Kelola profil, email login, penugasan mata pelajaran, dan wali kelas setiap pendidik secara terintegrasi.",category:"SDM & Akademik",color:"indigo",illustration:a.jsxs("svg",{viewBox:"0 0 200 200",fill:"none",xmlns:"http://www.w3.org/2000/svg",className:"w-full h-full drop-shadow-2xl",children:[a.jsx("circle",{cx:"100",cy:"100",r:"80",fill:"currentColor",fillOpacity:"0.1",className:"text-blue-500"}),a.jsx("path",{d:"M100 50C116.569 50 130 63.4315 130 80C130 96.5685 116.569 110 100 110C83.4315 110 70 96.5685 70 80C70 63.4315 83.4315 50 100 50Z",fill:"#8B5CF6"}),a.jsx("path",{d:"M40 170C40 140 70 120 100 120C130 120 160 140 160 170",stroke:"#8B5CF6",strokeWidth:"16",strokeLinecap:"round"})]})}),

    // Toolbar: cari, urutkan, filter role & status
    a.jsxs("div",{className:"bg-white rounded-[2rem] border border-slate-100 shadow-sm p-4 space-y-3",children:[
      a.jsxs("div",{className:"flex flex-col md:flex-row gap-3",children:[
        a.jsxs("div",{className:"relative flex-1",children:[a.jsx(Ui,{size:16,className:"absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"}),a.jsx("input",{type:"text",placeholder:"Cari nama / email / NIP...",value:l,onChange:Pe=>c(Pe.target.value),className:"bg-slate-50 border border-slate-100 rounded-2xl pl-10 pr-4 py-3 text-sm outline-none w-full focus:ring-2 focus:ring-blue-200 transition-all font-medium"})]}),
        a.jsxs("select",{value:kgSort,onChange:Pe=>kgSetSort(Pe.target.value),className:"bg-slate-50 border border-slate-100 rounded-2xl px-4 py-3 text-sm font-bold outline-none cursor-pointer focus:ring-2 focus:ring-blue-200","aria-label":"Urutkan",children:[a.jsx("option",{value:"NAMA",children:"Urut: Nama A–Z"}),a.jsx("option",{value:"LOGIN_BARU",children:"Urut: Login terbaru"}),a.jsx("option",{value:"LOGIN_LAMA",children:"Urut: Login terlama"})]})]}),
      a.jsxs("div",{className:"flex flex-wrap items-center gap-2",children:[
        ...["ALL","GURU","KEPALA_SEKOLAH","TATA_USAHA","ADMIN"].map(Pe=>kgChip(Pe,Pe==="ALL"?"Semua Role":Pe.replace("_"," "),h,u)),
        a.jsx("span",{className:"w-px h-5 bg-slate-100 mx-1"},"sep"),
        kgChip("ALL","Semua Status",kgSetStatus,kgStatus),kgChip("BELUM","Belum lengkap",kgSetStatus,kgStatus),kgChip("NONAKTIF","Tidak aktif >30 hari",kgSetStatus,kgStatus)]})]}),

    r?a.jsx("div",{className:"bg-white rounded-[2rem] border border-slate-100 p-10 text-center text-slate-400 text-xs font-bold",children:"Memuat data guru..."}):
    Ae.length===0?a.jsx("div",{className:"bg-white rounded-[2rem] border border-slate-100 p-10 text-center text-slate-400 text-xs font-bold",children:"Tidak ada pengguna ditemukan."}):
    a.jsxs(a.Fragment,{children:[
      // Kartu (< md)
      a.jsx("div",{className:"md:hidden space-y-3",children:Ae.map(Pe=>a.jsxs("button",{type:"button",onClick:()=>ge(Pe),className:"w-full text-left bg-white rounded-2xl border border-slate-100 shadow-sm p-4 space-y-3 cursor-pointer hover:border-blue-200 transition-all",children:[
        a.jsxs("div",{className:"flex items-center gap-3",children:[a.jsx(Ci,{src:Pe.photoURL,name:Pe.displayName||"U",className:"w-10 h-10 rounded-2xl shrink-0 bg-blue-50 text-blue-500"}),a.jsxs("div",{className:"min-w-0 flex-1",children:[a.jsx("p",{className:"font-bold truncate text-sm text-slate-800",children:Pe.displayName}),a.jsx("p",{className:"text-[11px] text-slate-400 truncate",children:Pe.email}),kgBadgeRole(Pe)]})]}),
        a.jsxs("div",{className:"flex flex-wrap items-center gap-2 text-[10px] font-bold text-slate-400",children:["Mapel",kgBadgeMapel(Pe),"Jadwal",kgBadgeJadwal(Pe)]}),
        a.jsxs("div",{className:"flex items-center justify-between gap-2",children:[kgBadgeLogin(Pe),Pe.nip&&a.jsx("span",{className:"text-[10px] text-slate-400 font-mono truncate",children:"NIP "+Pe.nip})]})]},Pe.uid))}),
      // Tabel (>= md)
      a.jsx("div",{className:"hidden md:block bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden",children:a.jsx("div",{className:"overflow-x-auto",children:a.jsxs("table",{className:"w-full text-sm text-left",children:[
        a.jsx("thead",{children:a.jsx("tr",{className:"bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest",children:["Pengguna","Email / NIP","Mapel","Jadwal KBM","Login Terakhir",""].map((Pe,Z)=>a.jsx("th",{className:"px-5 py-4 whitespace-nowrap",children:Pe},Z))})}),
        a.jsx("tbody",{className:"divide-y divide-slate-50",children:Ae.map(Pe=>a.jsxs("tr",{onClick:()=>ge(Pe),className:"hover:bg-blue-50 cursor-pointer transition-colors",children:[
          a.jsx("td",{className:"px-5 py-3.5",children:a.jsxs("div",{className:"flex items-center gap-3",children:[a.jsx(Ci,{src:Pe.photoURL,name:Pe.displayName||"U",className:"w-10 h-10 rounded-2xl shrink-0 bg-blue-50 text-blue-500"}),a.jsxs("div",{className:"min-w-0",children:[a.jsx("p",{className:"font-bold text-slate-800 text-sm",children:Pe.displayName}),kgBadgeRole(Pe)]})]})}),
          a.jsxs("td",{className:"px-5 py-3.5",children:[a.jsx("p",{className:"text-xs font-medium text-slate-600 truncate max-w-xs",title:Pe.email,children:Pe.email}),a.jsx("p",{className:"text-[10px] text-slate-400 font-mono mt-0.5",children:Pe.nip||"—"})]}),
          a.jsx("td",{className:"px-5 py-3.5",children:kgBadgeMapel(Pe)}),
          a.jsx("td",{className:"px-5 py-3.5",children:kgBadgeJadwal(Pe)}),
          a.jsx("td",{className:"px-5 py-3.5",children:kgBadgeLogin(Pe)}),
          a.jsx("td",{className:"px-5 py-3.5 text-right",children:a.jsxs("button",{type:"button",onClick:Z=>{Z.stopPropagation(),ge(Pe)},className:"inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold text-blue-600 bg-blue-50 hover:bg-blue-100 transition-all cursor-pointer whitespace-nowrap",children:[isAdmin?a.jsx(fue,{size:14}):a.jsx(O_,{size:14}),isAdmin?"Edit":"Lihat"]})})]},Pe.uid))})]})})})]}),

    a.jsxs("div",{className:"text-center text-[10px] text-slate-400 font-bold",children:[Ae.length," dari ",t.length," pengguna",kgJmlBelum>0&&a.jsxs("span",{className:"text-red-600",children:[" · ",kgJmlBelum," guru belum lengkap"]})]}),

    // Modal edit / lihat
    a.jsx(Si,{children:p&&a.jsx(Pt.div,{initial:{opacity:0},animate:{opacity:1},exit:{opacity:0},className:"fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm",onMouseDown:Pe=>{Pe.target===Pe.currentTarget&&!i&&kgTutup()},children:a.jsxs(Pt.div,{initial:{opacity:0,y:20},animate:{opacity:1,y:0},exit:{opacity:0,y:20},role:"dialog","aria-modal":"true",className:"bg-white rounded-[2.5rem] shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden flex flex-col",children:[
      a.jsxs("div",{className:"p-6 border-b border-slate-50 flex items-center justify-between gap-4 bg-gradient-to-r from-blue-50 to-white",children:[
        a.jsxs("div",{className:"flex items-center gap-4 min-w-0",children:[a.jsx(Ci,{src:p.photoURL,name:p.displayName||"U",className:"w-14 h-14 rounded-3xl shadow-sm bg-blue-100 text-blue-600 shrink-0"}),a.jsxs("div",{className:"min-w-0",children:[a.jsx("h2",{className:"text-xl font-black text-slate-800 truncate",children:p.displayName}),a.jsx("p",{className:"text-slate-400 text-sm font-medium truncate",children:p.email}),p.nip&&a.jsxs("p",{className:"text-slate-400 text-xs font-mono",children:["NIP: ",p.nip]})]})]}),
        a.jsx("button",{type:"button",onClick:()=>kgTutup(),disabled:i,"aria-label":"Tutup",className:"p-2 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all cursor-pointer shrink-0",children:a.jsx(pi,{size:20})})]}),
      a.jsxs("div",{className:"p-6 space-y-8 overflow-y-auto flex-1 custom-scrollbar",children:[
        !isAdmin&&a.jsxs("div",{className:"bg-blue-50 border border-blue-100 text-blue-700 rounded-xl px-4 py-3 text-[11px] font-bold",children:["Mode lihat saja. Perubahan data guru hanya dapat dilakukan oleh ADMIN."]}),
        // Akun login (email)
        a.jsxs("div",{children:[
          a.jsxs("h3",{className:"text-xs font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2",children:[a.jsx(zp,{size:14})," Akun Login"]}),
          a.jsxs("div",{className:"space-y-1.5",children:[
            a.jsx("label",{className:"text-[10px] font-black text-slate-400 uppercase tracking-widest",children:"Email Login"}),
            a.jsx("input",{type:"email",value:kgEmail,onChange:Pe=>kgSetEmail(Pe.target.value),disabled:!isAdmin||i,autoComplete:"off",className:kgInputCls}),
            isAdmin&&a.jsx("p",{className:"text-[10px] text-slate-400",children:"Email dipakai untuk login. Beri tahu pengguna bila email diubah."}),
            isAdmin&&!kgEmailValid&&a.jsx("p",{className:"text-[10px] font-bold text-red-600",children:"Format email tidak valid."}),
            isAdmin&&kgEmailValid&&kgEmailBerubah&&a.jsxs("div",{className:"flex items-start gap-2 bg-amber-50 border border-amber-200 text-amber-700 rounded-xl px-4 py-3 text-[11px] font-bold mt-2",children:[a.jsx(Z1,{size:14,className:"shrink-0 mt-0.5"}),a.jsxs("span",{children:["Login berikutnya harus memakai email baru: ",kgEmailBaru]})]})]})]}),
        // Hak akses & wali kelas
        a.jsxs("div",{className:"border-t border-slate-50 pt-6",children:[
          a.jsxs("h3",{className:"text-xs font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2",children:[a.jsx(J1,{size:14})," Hak Akses & Penugasan Kelas"]}),
          a.jsxs("div",{className:"grid grid-cols-1 sm:grid-cols-2 gap-4",children:[
            a.jsxs("div",{className:"space-y-1.5",children:[
              a.jsx("label",{className:"text-[10px] font-black text-slate-400 uppercase tracking-widest",children:"Role Akun"}),
              a.jsxs("select",{value:y,onChange:Pe=>N(Pe.target.value),disabled:!isAdmin||i,className:$e(kgInputCls,"cursor-pointer"),children:[a.jsx("option",{value:"GURU",children:"GURU"}),a.jsx("option",{value:"KEPALA_SEKOLAH",children:"KEPALA SEKOLAH"}),a.jsx("option",{value:"ADMIN",children:"ADMIN"}),y==="TATA_USAHA"&&a.jsx("option",{value:"TATA_USAHA",children:"TATA USAHA"})]}),
              a.jsxs("button",{type:"button",disabled:!isAdmin||i,onClick:()=>R(Pe=>Pe===1?0:1),className:$e("w-full mt-2 flex items-center gap-3 px-4 py-3 rounded-xl border text-sm font-bold transition-all cursor-pointer disabled:opacity-60",T===1?"bg-blue-50 border-blue-200 text-blue-700":"bg-slate-50 border-slate-100 text-slate-500 hover:border-blue-200"),children:[a.jsx("div",{className:$e("w-10 h-5 rounded-full transition-all relative flex-shrink-0",T===1?"bg-blue-600":"bg-slate-200"),children:a.jsx("span",{className:$e("absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-all",T===1?"left-5":"left-0.5")})}),a.jsx("span",{className:"text-[11px] font-black tracking-wide",children:T===1?"✓ Ditandai sebagai Guru BK/Konselor":"Tandai sebagai Guru BK/Konselor"})]})]}),
            a.jsxs("div",{className:"space-y-1.5",children:[
              a.jsxs("label",{className:"text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center gap-1",children:[a.jsx(X1,{size:11})," Wali Kelas ",a.jsx("span",{className:"text-slate-300",children:"(Opsional)"})]}),
              a.jsxs("select",{value:A,onChange:Pe=>E(Pe.target.value),disabled:!isAdmin||i,className:$e(kgInputCls,"cursor-pointer"),children:[a.jsx("option",{value:"",children:"— Bukan Wali Kelas —"}),Object.entries(Ce).map(([Pe,Z])=>a.jsx("optgroup",{label:"Kelas "+Pe,children:Z.map(ht=>a.jsx("option",{value:ht.id,children:ht.name||ht.id},ht.id))},Pe))]})]})]})]}),
        // Ganti password (ADMIN)
        isAdmin&&a.jsxs("div",{className:"border-t border-slate-50 pt-6",children:[
          a.jsxs("button",{type:"button",onClick:()=>z(!M),className:"flex items-center gap-2 text-xs font-black text-slate-400 uppercase tracking-widest hover:text-blue-600 transition-colors cursor-pointer",children:[a.jsx(Rce,{size:14}),M?"Sembunyikan Form Ganti Password":"Ganti Password Akun Ini",a.jsx(Ule,{size:14,className:$e("transition-transform",M&&"rotate-180")})]}),
          M&&a.jsxs("div",{className:"pt-4",children:[a.jsx("input",{type:"text",value:U,onChange:Pe=>I(Pe.target.value),disabled:i,placeholder:"Ketik sandi baru...",className:$e(kgInputCls,"font-mono")}),a.jsx("p",{className:"text-[10px] text-slate-400 mt-1.5",children:"Password akan dienkripsi otomatis saat disimpan."})]})]}),
        // Penugasan mata pelajaran
        a.jsxs("div",{className:"border-t border-slate-50 pt-6",children:[
          a.jsxs("h3",{className:"text-xs font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2",children:[a.jsx(lc,{size:14})," Penugasan Mata Pelajaran"]}),
          isAdmin&&a.jsxs("div",{className:"bg-slate-50 rounded-2xl p-5 space-y-4 border border-slate-100 mb-5",children:[
            a.jsx("p",{className:"text-[10px] font-black text-slate-400 uppercase tracking-widest",children:"Tambah Penugasan Baru"}),
            a.jsxs("div",{className:"grid grid-cols-1 sm:grid-cols-2 gap-3",children:[
              a.jsxs("div",{children:[a.jsx("label",{className:"text-[9px] font-black text-slate-400 uppercase tracking-wider block mb-1",children:"Mata Pelajaran"}),a.jsxs("select",{value:H,onChange:Pe=>Y(Pe.target.value),className:"w-full px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-bold outline-none bg-white cursor-pointer focus:ring-2 focus:ring-blue-200",children:[a.jsx("option",{value:"",children:"— Pilih Mapel —"}),X.map(Pe=>a.jsx("option",{value:Pe.id,children:Pe.name},Pe.id))]})]}),
              a.jsxs("div",{children:[a.jsx("label",{className:"text-[9px] font-black text-slate-400 uppercase tracking-wider block mb-1",children:"Level Kelas"}),a.jsxs("select",{value:ne,onChange:Pe=>{K(Pe.target.value),de([])},className:"w-full px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-bold outline-none bg-white cursor-pointer focus:ring-2 focus:ring-blue-200",children:[a.jsx("option",{value:"",children:"— Semua Level —"}),a.jsx("option",{value:"X",children:"Kelas X"}),a.jsx("option",{value:"XI",children:"Kelas XI"}),a.jsx("option",{value:"XII",children:"Kelas XII"})]})]})]}),
            ne&&ke.length>0&&a.jsxs("div",{children:[a.jsxs("label",{className:"text-[9px] font-black text-slate-400 uppercase tracking-wider block mb-2",children:["Kelas Spesifik yang Diampu ",a.jsx("span",{className:"text-slate-300",children:"(Opsional — kosongkan = semua)"})]}),a.jsx("div",{className:"grid grid-cols-3 sm:grid-cols-4 gap-2",children:ke.map(Pe=>{const Z=me.includes(Pe.id);return a.jsxs("button",{type:"button",onClick:()=>ce(Pe.id),className:$e("flex items-center gap-1.5 px-3 py-2 rounded-xl border text-xs font-bold transition-all cursor-pointer",Z?"bg-blue-600 text-white border-blue-600":"bg-white text-slate-600 border-slate-200 hover:border-blue-300"),children:[Z?a.jsx(Due,{size:12}):a.jsx(vW,{size:12}),Pe.id]},Pe.id)})})]}),
            a.jsxs("button",{type:"button",onClick:oe,disabled:!H,className:"w-full py-2.5 bg-blue-600 text-white rounded-xl text-xs font-bold hover:bg-blue-700 transition-all disabled:opacity-40 flex items-center justify-center gap-2 cursor-pointer",children:[a.jsx(es,{size:16})," Tambahkan ke Daftar"]})]}),
          a.jsx("div",{className:"space-y-2",children:kgMuat?a.jsx("div",{className:"text-center py-8 text-slate-400 text-xs font-bold",children:"Memuat penugasan..."}):P.length===0?a.jsxs("div",{className:"text-center py-10 text-slate-400 text-xs font-bold uppercase tracking-widest border-2 border-dashed border-slate-100 rounded-2xl",children:[a.jsx(z2,{size:32,className:"mx-auto mb-2 opacity-30"}),"Belum ada mapel ditugaskan"]}):P.map((Pe,Z)=>a.jsxs("div",{className:"flex items-start justify-between p-4 bg-slate-50 border border-slate-100 rounded-2xl gap-3",children:[
            a.jsxs("div",{className:"flex-1 min-w-0",children:[a.jsxs("div",{className:"flex items-center gap-2 flex-wrap",children:[a.jsx("span",{className:"px-2 py-0.5 bg-white border border-slate-200 rounded-lg text-[10px] font-black uppercase text-slate-500",children:Pe.subject_code}),a.jsx("span",{className:"font-bold text-slate-800 text-sm",children:Pe.subject_name}),Pe.class_level&&a.jsxs("span",{className:"px-2 py-0.5 bg-blue-50 text-blue-600 rounded-lg text-[10px] font-bold",children:["Kelas ",Pe.class_level]})]}),Pe.class_ids&&Pe.class_ids.length>0&&a.jsx("div",{className:"flex flex-wrap gap-1 mt-2",children:Pe.class_ids.map(ht=>a.jsx("span",{className:"px-2 py-0.5 bg-blue-50 text-blue-600 rounded-md text-[10px] font-bold",children:ht},ht))})]}),
            isAdmin&&a.jsx("button",{type:"button",onClick:()=>be(Z),"aria-label":"Hapus penugasan",className:"text-slate-300 hover:text-red-500 p-1 mt-0.5 shrink-0 transition-colors cursor-pointer",children:a.jsx(pi,{size:16})})]},Z))})]})]}),
      kgErr&&a.jsx("div",{className:"px-6 pt-4",children:a.jsxs("div",{className:"flex items-start gap-2 bg-red-50 border border-red-100 text-red-600 rounded-xl px-4 py-3 text-xs font-bold",children:[a.jsx(Z1,{size:14,className:"shrink-0 mt-0.5"}),a.jsx("span",{children:kgErr})]})}),
      a.jsx("div",{className:"p-5 border-t border-slate-50 flex items-center justify-end gap-3",children:isAdmin?a.jsxs(a.Fragment,{children:[
        a.jsx("button",{type:"button",onClick:()=>kgTutup(),disabled:i,className:"px-5 py-3 rounded-2xl font-bold text-sm text-slate-500 hover:bg-slate-100 transition-all cursor-pointer",children:"Batal"}),
        a.jsxs("button",{type:"button",onClick:we,disabled:i||kgMuat||!kgEmailValid,className:"bg-blue-600 text-white px-6 py-3 rounded-2xl font-bold shadow-lg shadow-blue-100 hover:bg-blue-700 transition-all flex items-center gap-2 disabled:opacity-70 cursor-pointer",children:[a.jsx(s3,{size:18})," ",i?"Menyimpan...":"Simpan Semua"]})]}):
        a.jsx("button",{type:"button",onClick:()=>kgTutup(!0),className:"px-6 py-3 rounded-2xl font-bold text-sm text-white bg-blue-600 hover:bg-blue-700 transition-all cursor-pointer",children:"Tutup"})})]})},"kg-modal")})]});
}

// ---------------------------------------------------------------------------
const src = fs.readFileSync(srcPath, 'utf8');
const hash = crypto.createHash('sha256').update(fs.readFileSync(srcPath)).digest('hex');
if (hash !== SRC_SHA256) fail(`hash ${SRC_NAME} berbeda (${hash}). Bundle sudah berubah, cari ulang anchor secara manual.`);

// K1-K4: ganti seluruh komponen lot (dari deklarasi sampai tepat sebelum `const Ym=`)
const START = 'function lot({user:e}){';
const END = '}const Ym={MIPA:';
if (src.split(START).length - 1 !== 1) fail('anchor awal komponen lot tidak ditemukan tepat 1 kali.');
const iStart = src.indexOf(START);
const iEnd = src.indexOf(END, iStart);
if (iEnd < 0) fail('anchor akhir komponen lot tidak ditemukan.');
const lama = src.slice(iStart, iEnd + 1);
if (!lama.includes('lg:grid-cols-[320px_1fr]') || !lama.includes('"Pilih Guru untuk Dikelola"')) {
  fail('isi komponen lot tidak sesuai bundle acuan.');
}
// Buang baris komentar (komentar hanya ditulis di baris sendiri) dan indentasi.
const baru = lot.toString().split('\n').map((b) => b.replace(/^\s*\/\/.*$/, '').trim()).filter(Boolean).join('');
let out = src.slice(0, iStart) + baru + src.slice(iEnd + 1);
console.log('OK  K1-K4 komponen lot diganti');

// Validasi kelas Tailwind: setiap token di string className harus ada di CSS precompiled
const css = fs.readFileSync(path.join(ROOT, 'assets', 'index-CC1PBzaO.css'), 'utf8');
const esc = (k) => k.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
const tokens = new Set();
for (const m of baru.matchAll(/"([^"]*)"/g)) {
  const s = m[1];
  if (!/^[a-z0-9!\-[\]/:.%()#_ ]+$/i.test(s)) continue;
  for (const k of s.split(/\s+/)) if (/^-?[a-z][a-z0-9-]*(?:[:/[\].%-][a-z0-9[\]/.%()#_-]*)*$/.test(k) && /-|:/.test(k) && !k.endsWith(':') && !k.includes('//')) tokens.add(k);
}
const bukanKelas = new Set(['aria-label', 'aria-modal', 'id-ID', 'kg-modal', 'custom-scrollbar', 'no-scrollbar']);
// Cocok utuh: setelah nama kelas harus pemisah selektor (bukan prefix, mis. .w-2 vs .w-20)
const adaDiCss = (k) => {
  const sel = '.' + esc(k);
  for (let p = css.indexOf(sel); p >= 0; p = css.indexOf(sel, p + 1)) {
    const ch = css[p + sel.length];
    if (ch === undefined || /[\s{,:>~+)[]/.test(ch)) return true;
  }
  return false;
};
const hilang = [...tokens].filter((k) => !bukanKelas.has(k) && !adaDiCss(k));
if (hilang.length) fail('kelas Tailwind tidak ada di CSS: ' + hilang.join(' '));
console.log(`OK  ${tokens.size} token kelas diperiksa`);

// Identifier bundle yang dipakai komponen harus ada di bundle sumber
for (const id of ['zi', 'Ci', 'Si', 'Pt', 'U$', 'Ui', 's3', 'J1', 'X1', 'Rce', 'Ule', 'lc', 'Due', 'vW', 'es', 'z2', 'pi', 'zp', 'Z1', 'fue', 'O_']) {
  const re = new RegExp('(?:function |const |let |var |,)' + id.replace('$', '\\$') + '(?:=|\\()');
  if (!re.test(src)) fail(`identifier ${id} tidak ditemukan di bundle sumber.`);
}
console.log('OK  identifier bundle tersedia');

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
