// Patch bundle frontend: persetujuan massal jurnal bulanan oleh Kepala Sekolah / ADMIN.
// Spesifikasi: docs/specs/persetujuan-jurnal-bulanan.md
//  P0 Komponen modal "Persetujuan Jurnal Bulanan" (ModalPersetujuanJurnal).
//  P1 API client: et.journals.previewMonth(month) dan et.journals.approveMonth(month, ids).
//  P2 Halaman Jurnal (XTe): state modal, tombol "Setujui Jurnal Bulanan" (KEPALA_SEKOLAH/ADMIN),
//     render modal, dan pembaruan status jurnal di daftar setelah disetujui.
//  P3 Default bulan modal = filter bulan (N, singkatan "Jan".."Des") + tahun (E) halaman.
// Prasyarat: patch detail jurnal/cetak (apply-detail-jurnal-cetak-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-persetujuan-jurnal-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-jurnal1.js';
const OUT_NAME = 'index-CGWL69P7-setuju1.js';
const SRC_SHA256 = '7b3471f6af1850a80fdaadbadcbca3b45db7fe9cd1e351e41ef4033b2eff63aa';
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

// ---------------------------------------------------------------------------
// P0 Komponen modal. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas yang dipakai berasal dari scope modul bundle:
//   a = jsx runtime, ue = React, et = API client,
//   ikon lucide: pi (x), Cue (shield-check), Z1 (triangle-alert), cr (circle-check), po (refresh-cw).
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */
function ModalPersetujuanJurnal({ defaultYm, onClose, onApproved }) {
  const NAMA_BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
  const kini = new Date();
  const tahunIni = kini.getFullYear();
  const bulanIni = `${tahunIni}-${String(kini.getMonth() + 1).padStart(2, '0')}`;
  const [ym, setYm] = ue.useState(defaultYm && defaultYm <= bulanIni ? defaultYm : bulanIni);
  const [data, setData] = ue.useState(null);
  const [memuat, setMemuat] = ue.useState(true);
  const [proses, setProses] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const [hasil, setHasil] = ue.useState(null);
  const tahun = parseInt(ym.slice(0, 4), 10);
  const bulan = parseInt(ym.slice(5, 7), 10);

  const pesanGalat = (err) => {
    try {
      const j = JSON.parse(err && err.message);
      if (j && j.error) return j.error === 'Forbidden' ? 'Akses ditolak' : j.error === 'Unauthorized' ? 'Sesi berakhir, silakan login ulang' : j.error;
    } catch (_) { /* bukan JSON */ }
    return 'Terjadi kesalahan server';
  };
  const tanggalId = (s) => {
    const p = String(s || '').split('-');
    return p.length === 3 ? `${parseInt(p[2], 10)} ${NAMA_BULAN[parseInt(p[1], 10) - 1]} ${p[0]}` : '-';
  };
  const gantiBulan = (y, m) => {
    let v = `${y}-${String(m).padStart(2, '0')}`;
    if (v > bulanIni) v = bulanIni;
    setYm(v);
  };

  ue.useEffect(() => {
    let aktif = true;
    setMemuat(true); setGalat(''); setData(null); setHasil(null);
    et.journals.previewMonth(ym)
      .then((d) => { if (aktif) setData(d); })
      .catch((err) => { if (aktif) setGalat(pesanGalat(err)); })
      .finally(() => { if (aktif) setMemuat(false); });
    return () => { aktif = false; };
  }, [ym]);

  const jumlah = data && data.eligible ? data.eligible.count : 0;
  const bisaSetujui = !memuat && !proses && !hasil && data && jumlah > 0;

  const setujui = async () => {
    if (!bisaSetujui) return;
    setProses(true); setGalat('');
    try {
      const r = await et.journals.approveMonth(ym, data.eligible.ids);
      setHasil(r);
      if (r && r.approved > 0) onApproved(r);
    } catch (err) {
      setGalat(pesanGalat(err));
    } finally {
      setProses(false);
    }
  };

  const daftarTahun = [];
  for (let y = tahunIni; y >= tahunIni - 4; y--) daftarTahun.push(y);
  const kelasSelect = 'px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-700 outline-none cursor-pointer';
  const kartu = (label, nilai, warna) => a.jsxs('div', {
    className: 'p-4 rounded-2xl bg-slate-50 border border-slate-100',
    children: [
      a.jsx('p', { className: 'text-[10px] font-black text-slate-400 uppercase tracking-widest', children: label }),
      a.jsx('p', { className: `text-xl font-black mt-1 ${warna}`, children: nilai }),
    ],
  });

  return a.jsx('div', {
    // Overlay yang menggulir + wrapper min-h-screen: modal di tengah bila muat, dan tetap bisa
    // digulir dari atas pada layar pendek (items-center langsung di overlay memotong bagian atas).
    className: 'fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 overflow-y-auto',
    children: a.jsx('div', {
      className: 'min-h-screen flex items-center justify-center p-4',
      onClick: (ev) => { if (ev.target === ev.currentTarget && !proses) onClose(); },
      children: a.jsxs('div', {
      className: 'bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden',
      children: [
        // Header
        a.jsxs('div', {
          className: 'p-6 border-b border-slate-100 flex items-start justify-between gap-4',
          children: [
            a.jsxs('div', {
              className: 'flex items-center gap-3',
              children: [
                a.jsx('div', { className: 'w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0', children: a.jsx(Cue, { size: 20 }) }),
                a.jsxs('div', {
                  children: [
                    a.jsx('h3', { className: 'text-lg font-black text-slate-800', children: 'Persetujuan Jurnal Bulanan' }),
                    a.jsx('p', { className: 'text-xs font-bold text-slate-400', children: 'Setujui sekaligus semua jurnal yang sudah dikirim guru pada bulan terpilih.' }),
                  ],
                }),
              ],
            }),
            a.jsx('button', {
              type: 'button', onClick: onClose, disabled: proses, title: 'Tutup',
              className: 'p-2 rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-all cursor-pointer',
              children: a.jsx(pi, { size: 18 }),
            }),
          ],
        }),
        // Isi
        a.jsxs('div', {
          className: 'p-6 space-y-5',
          children: [
            a.jsxs('div', {
              className: 'flex flex-wrap items-center gap-3',
              children: [
                a.jsx('select', {
                  className: kelasSelect, value: bulan, disabled: proses,
                  onChange: (ev) => gantiBulan(tahun, parseInt(ev.target.value, 10)),
                  children: NAMA_BULAN.map((nm, i) => a.jsx('option', {
                    value: i + 1,
                    disabled: `${tahun}-${String(i + 1).padStart(2, '0')}` > bulanIni,
                    children: nm,
                  }, nm)),
                }),
                a.jsx('select', {
                  className: kelasSelect, value: tahun, disabled: proses,
                  onChange: (ev) => gantiBulan(parseInt(ev.target.value, 10), bulan),
                  children: daftarTahun.map((y) => a.jsx('option', { value: y, children: y }, y)),
                }),
              ],
            }),
            memuat && a.jsxs('div', {
              className: 'flex items-center gap-2 text-slate-400 font-bold text-xs',
              children: [a.jsx(po, { size: 14, className: 'animate-spin' }), ' Memuat ringkasan jurnal...'],
            }),
            galat && a.jsx('div', {
              className: 'p-4 rounded-2xl bg-rose-50 border border-rose-100 text-rose-600 text-xs font-bold',
              children: galat,
            }),
            hasil && a.jsxs('div', {
              className: 'p-4 rounded-2xl bg-emerald-50 border border-emerald-100 text-emerald-700 text-sm font-bold flex items-start gap-2',
              children: [
                a.jsx(cr, { size: 18, className: 'shrink-0' }),
                a.jsxs('div', {
                  children: [
                    a.jsx('p', { children: hasil.approved > 0 ? `${hasil.approved} jurnal berhasil disetujui.` : 'Tidak ada jurnal yang disetujui.' }),
                    hasil.skipped > 0 && a.jsx('p', { className: 'text-xs mt-1', children: `${hasil.skipped} jurnal dilewati karena statusnya berubah (sudah diverifikasi, dihapus, atau tanggalnya dipindah).` }),
                  ],
                }),
              ],
            }),
            data && !hasil && a.jsxs(a.Fragment, {
              children: [
                a.jsxs('div', {
                  className: 'p-5 rounded-2xl bg-emerald-50 border border-emerald-100',
                  children: [
                    a.jsx('p', { className: 'text-[10px] font-black text-emerald-700 uppercase tracking-widest', children: 'Akan disetujui' }),
                    a.jsxs('p', {
                      className: 'text-3xl font-black text-emerald-700 mt-1',
                      children: [jumlah, a.jsx('span', { className: 'text-sm font-bold', children: ' jurnal' })],
                    }),
                    a.jsx('p', { className: 'text-xs font-bold text-slate-500 mt-1', children: `Periode ${tanggalId(data.start)} s.d. ${tanggalId(data.cutoff)}` }),
                  ],
                }),
                a.jsxs('div', {
                  className: 'grid grid-cols-1 sm:grid-cols-3 gap-3',
                  children: [
                    kartu('Draf (tidak ikut)', data.excluded.draft, 'text-slate-700'),
                    kartu('Setelah hari ini (tidak ikut)', data.excluded.futureDated, 'text-slate-700'),
                    kartu('Sudah terverifikasi', data.alreadyVerified, 'text-slate-700'),
                  ],
                }),
                jumlah > 0 ? a.jsx('div', {
                  className: 'max-h-[300px] overflow-y-auto border border-slate-100 rounded-2xl',
                  children: a.jsxs('table', {
                    className: 'w-full text-left',
                    children: [
                      a.jsx('thead', {
                        children: a.jsxs('tr', {
                          className: 'bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest',
                          children: [
                            a.jsx('th', { className: 'px-4 py-3', children: 'Guru' }),
                            a.jsx('th', { className: 'px-4 py-3 text-right', children: 'Jurnal' }),
                          ],
                        }),
                      }),
                      a.jsx('tbody', {
                        children: data.eligible.byTeacher.map((g) => a.jsxs('tr', {
                          className: 'border-t border-slate-100 text-sm',
                          children: [
                            a.jsx('td', { className: 'px-4 py-2.5 font-bold text-slate-700', children: g.teacherName || g.teacherId }),
                            a.jsx('td', { className: 'px-4 py-2.5 font-black text-slate-800 text-right', children: g.count }),
                          ],
                        }, g.teacherId)),
                      }),
                    ],
                  }),
                }) : a.jsx('p', {
                  className: 'text-center text-xs font-bold text-slate-400',
                  children: 'Tidak ada jurnal yang perlu disetujui pada bulan ini.',
                }),
                a.jsxs('div', {
                  className: 'flex items-start gap-2 text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-100 rounded-2xl p-4',
                  children: [
                    a.jsx(Z1, { size: 16, className: 'shrink-0' }),
                    a.jsx('p', { children: 'Semua guru ikut disetujui. Filter guru dan pencarian di halaman tidak berlaku. Draf dan jurnal bertanggal setelah hari ini tidak ikut. Persetujuan tidak dapat dibatalkan.' }),
                  ],
                }),
              ],
            }),
          ],
        }),
        // Footer
        a.jsxs('div', {
          className: 'p-6 border-t border-slate-100 bg-slate-50 flex justify-end gap-3',
          children: [
            a.jsx('button', {
              type: 'button', onClick: onClose, disabled: proses,
              className: 'px-6 py-3 rounded-2xl font-black text-xs uppercase tracking-widest text-slate-500 bg-white border border-slate-200 hover:bg-slate-100 transition-all cursor-pointer',
              children: hasil ? 'Tutup' : 'Batal',
            }),
            !hasil && a.jsxs('button', {
              type: 'button', onClick: setujui, disabled: !bisaSetujui,
              className: 'px-6 py-3 rounded-2xl font-black text-xs uppercase tracking-widest text-white bg-emerald-600 hover:bg-emerald-700 transition-all shadow-lg flex items-center gap-2 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed',
              children: [
                proses ? a.jsx(po, { size: 14, className: 'animate-spin' }) : a.jsx(cr, { size: 14 }),
                proses ? ' Memproses...' : ` Setujui ${jumlah} Jurnal`,
              ],
            }),
          ],
        }),
      ],
      }),
    }),
  });
}
/* eslint-enable no-undef */

const MODAL_SRC = ModalPersetujuanJurnal.toString();

// P3: bulan filter halaman (N = "Jan".."Des", E = tahun string) -> 'YYYY-MM'
const DEFAULT_YM = '`${E}-${String(["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"].indexOf(N)+1).padStart(2,"0")}`';

// P2: tandai jurnal yang disetujui di state daftar (r/s) tanpa reload
const ON_APPROVED = '(res)=>{const ids=new Set(res.approvedIds||[]),at=new Date().toISOString();s(G=>G.map(ge=>ids.has(ge.id)?{...ge,isVerified:!0,verifiedBy:n.uid,verifiedByName:n.displayName||"Kepala Sekolah",verifiedAt:at}:ge))}';

const TOMBOL_SETUJUI = ',n&&(n.role==="KEPALA_SEKOLAH"||n.role==="ADMIN")&&a.jsxs("button",{onClick:()=>setSetujuOpen(!0),className:"bg-emerald-600 text-white px-6 py-3 rounded-2xl font-black text-xs uppercase tracking-widest hover:shadow-xl transition-all shadow-lg flex items-center gap-2",title:"Setujui sekaligus semua jurnal terkirim pada satu bulan",children:[a.jsx(Cue,{size:16})," Setujui Jurnal Bulanan"]})';

const patches = [
  {
    id: 'P0 Komponen modal persetujuan jurnal bulanan',
    from: 'function XTe({onAddJournal:e,onEditJournal:t,user:n}){',
    to: MODAL_SRC + 'function XTe({onAddJournal:e,onEditJournal:t,user:n}){',
  },
  {
    id: 'P1 API client previewMonth / approveMonth',
    from: 'verify:(e,t)=>yt("verify_journal","POST",{id:e,verifiedBy:t})}',
    to: 'verify:(e,t)=>yt("verify_journal","POST",{id:e,verifiedBy:t}),previewMonth:e=>yt("preview_journal_month_approval&month="+encodeURIComponent(e)),approveMonth:(e,t)=>yt("approve_journals_month","POST",{month:e,ids:t})}',
  },
  {
    id: 'P2a State modal di XTe',
    from: '[Y,ne]=ue.useState("grid"),',
    to: '[Y,ne]=ue.useState("grid"),[setujuOpen,setSetujuOpen]=ue.useState(!1),',
  },
  {
    id: 'P2b Tombol "Setujui Jurnal Bulanan" setelah "Laporan Bulanan"',
    from: 'a.jsx(Ti,{size:16,className:"text-slate-400"})," Laporan Bulanan"]})',
    to: 'a.jsx(Ti,{size:16,className:"text-slate-400"})," Laporan Bulanan"]})' + TOMBOL_SETUJUI,
  },
  {
    id: 'P2c Render modal (P3 default bulan dari filter halaman)',
    from: 'I&&a.jsx(ZTe,{onClose:()=>M(!1)}),',
    to: 'I&&a.jsx(ZTe,{onClose:()=>M(!1)}),setujuOpen&&a.jsx(ModalPersetujuanJurnal,{defaultYm:' + DEFAULT_YM + ',onClose:()=>setSetujuOpen(!1),onApproved:' + ON_APPROVED + '}),',
  },
  {
    // Dengan tombol ketiga, tab bulan + tombol tidak muat satu baris: grup tombol turun ke baris
    // berikutnya alih-alih memotong tab bulan. Tampilan dua tombol (GURU) tidak berubah.
    id: 'P2d Header halaman Jurnal boleh membungkus (flex-wrap)',
    from: 'a.jsxs("div",{className:"flex flex-col md:flex-row md:items-center justify-between gap-6",children:[a.jsx("div",{className:"flex items-center gap-6 overflow-x-auto no-scrollbar pb-2 md:pb-0"',
    to: 'a.jsxs("div",{className:"flex flex-col md:flex-row flex-wrap md:items-center justify-between gap-6",children:[a.jsx("div",{className:"flex items-center gap-6 overflow-x-auto no-scrollbar pb-2 md:pb-0"',
  },
];

// Nama baru tidak boleh sudah ada di bundle sumber.
for (const nama of ['ModalPersetujuanJurnal', 'setujuOpen', 'setSetujuOpen', 'previewMonth', 'approveMonth']) {
  if (src.includes(nama)) fail(`identifier ${nama} sudah ada di bundle sumber.`);
}

// Semua class Tailwind yang disisipkan harus sudah ada di CSS terkompilasi.
const css = fs.readFileSync(cssPath, 'utf8');
const cssEscape = (c) => c.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
const injected = MODAL_SRC + TOMBOL_SETUJUI;
const kelas = new Set();
for (const m of injected.matchAll(/(?:className:\s*|kelasSelect\s*=\s*)(['"`])([^'"`]*?)\1/g)) {
  m[2].split(/\s+/).filter((c) => c && !c.includes('${')).forEach((c) => kelas.add(c));
}
// class yang dirakit dari parameter kartu(), dan class baru di P2d
['text-xl', 'mt-1', 'text-slate-700', 'flex-wrap'].forEach((c) => kelas.add(c));
const hilang = [...kelas].filter((c) => {
  const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
  return !re.test(css);
});
if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));
console.log(`OK  ${kelas.size} class Tailwind tersedia di CSS bundle`);

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
