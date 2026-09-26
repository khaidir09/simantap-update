// Patch bundle frontend: Pantau Nilai Kelas untuk wali kelas dan guru BK (hanya baca).
// Spesifikasi: docs/specs/pantau-nilai-wali-kelas.md (§5)
//  P0 Komponen PantauNilaiKelas (__pnkPantauNilaiKelas) + peta class Tailwind (__pnkK),
//     disisipkan tepat sebelum komponen Dashboard Kelas Saya (dot).
//  P1 Dashboard Kelas Saya: tab baru "📝 Nilai".
//  P2 Dashboard Kelas Saya: render komponen saat tab Nilai aktif.
//  P3 Sidebar bagian "Bimbingan & Konseling": item "Pantau Nilai Kelas" (bk-grades).
//  P3b Blok sidebar BK dirender di grup menu pertama (grup "Kepegawaian" sudah tidak ada).
//  P4 Router halaman: bk-grades merender komponen dengan pemilih kelas.
//  P5 Daftar halaman yang dikenal: tambah bk-grades (agar tidak tampil "Fitur ... sedang dikembangkan").
// Prasyarat: patch hapus presensi pegawai (apply-hapus-presensi-pegawai-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-pantau-nilai-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-tanpapresensi1.js';
const OUT_NAME = 'index-CGWL69P7-pantaunilai1.js';
const SRC_SHA256 = '6b8ec613af1950f1b209a96ebc2d59b080dd03aa9b630c38e46e99da751fb545';
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
// Semua class Tailwind komponen ada di sini (divalidasi terhadap CSS bundle di bawah).
// ---------------------------------------------------------------------------
const BADGE = 'inline-block text-[10px] font-black px-2 py-0.5 rounded-full';
const SEL = 'inline-block min-w-[24px] px-2 py-1 rounded-lg text-xs font-black';
const TOGGLE = 'px-6 py-2 rounded-xl text-xs font-black uppercase tracking-wider transition-all';
const KELAS = {
  halaman: 'max-w-6xl mx-auto space-y-8 pb-20',
  wrap: 'space-y-6',
  bar: 'flex flex-wrap items-center justify-between gap-3',
  barKiri: 'flex flex-wrap items-center gap-2',
  select: 'px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-600',
  input: 'px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600',
  toggleWrap: 'flex bg-slate-100 p-1.5 rounded-2xl w-fit',
  toggleOn: TOGGLE + ' bg-white text-emerald-600 shadow-sm',
  toggleOff: TOGGLE + ' text-slate-400 hover:text-slate-600',
  btnPutih: 'flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-500 hover:bg-slate-50 transition-all',
  btnHijau: 'flex items-center gap-2 px-4 py-2 bg-emerald-600 border border-emerald-600 rounded-xl text-xs font-black text-white hover:bg-emerald-700 transition-all',
  periode: 'text-xs font-black text-slate-400 uppercase tracking-widest',
  statGrid: 'grid grid-cols-2 md:grid-cols-4 gap-4',
  stat: 'bg-white rounded-3xl border border-slate-100 p-5',
  statLabel: 'text-[10px] font-black text-slate-400 uppercase tracking-widest',
  statNilai: 'text-2xl font-black text-slate-800 mt-0.5',
  statNilaiMerah: 'text-2xl font-black text-red-600 mt-0.5',
  card: 'bg-white rounded-[2.5rem] border border-slate-100 overflow-hidden shadow-sm',
  cardHead: 'p-6 border-b border-slate-50 flex flex-wrap items-center justify-between gap-3',
  judulKecil: 'text-xs font-black text-slate-400 uppercase tracking-widest',
  subjudul: 'text-lg font-black text-slate-800',
  teks: 'text-sm text-slate-500',
  mapelGrid: 'grid grid-cols-1 md:grid-cols-3 gap-3 p-6',
  mapelBtn: 'text-left p-4 rounded-2xl border border-slate-100 hover:bg-slate-50 transition-all',
  mapelNama: 'text-sm font-black text-slate-800',
  mapelInfo: 'text-[11px] font-bold text-slate-400 mt-1',
  badgeAbu: BADGE + ' bg-slate-100 text-slate-500',
  badgeMerah: BADGE + ' bg-red-50 text-red-700',
  badgeHijau: BADGE + ' bg-emerald-50 text-emerald-700',
  badgeKuning: BADGE + ' bg-amber-50 text-amber-700',
  tabelWrap: 'overflow-x-auto',
  tabel: 'w-full text-left',
  thead: 'bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest',
  th: 'px-4 py-3 whitespace-nowrap',
  thKlik: 'px-4 py-3 whitespace-nowrap cursor-pointer hover:text-slate-600',
  thTengah: 'px-3 py-3 text-center whitespace-nowrap',
  thTengahKlik: 'px-3 py-3 text-center whitespace-nowrap cursor-pointer hover:text-slate-600',
  tbody: 'divide-y divide-slate-50',
  tr: 'hover:bg-slate-50/50 transition-colors',
  tdNama: 'px-4 py-3 whitespace-nowrap',
  td: 'px-4 py-3 text-sm text-slate-600',
  tdTengah: 'px-3 py-3 text-center text-sm',
  namaBtn: 'font-bold text-slate-700 hover:text-emerald-600 text-left',
  nisn: 'block text-[10px] font-bold text-slate-400',
  selHijau: SEL + ' bg-emerald-50 text-emerald-700',
  selMerah: SEL + ' bg-red-50 text-red-700',
  selSementara: SEL + ' bg-amber-50 text-amber-700',
  selKosong: SEL + ' text-slate-300',
  miss: 'block text-[10px] font-black text-red-600 mt-0.5',
  selBtn: 'w-full px-2 py-1 rounded-lg hover:bg-slate-100 transition-all',
  selBaris: 'block text-xs font-black text-slate-700',
  selBarisMerah: 'block text-xs font-black text-red-600',
  selKet: 'block text-[10px] font-bold text-slate-400',
  kosong: 'py-16 text-center text-slate-400 font-bold text-sm',
  spinnerWrap: 'py-20 text-center space-y-3',
  spinner: 'w-10 h-10 border-4 border-emerald-600 border-t-transparent rounded-full animate-spin mx-auto',
  spinnerTeks: 'text-slate-400 font-bold text-sm',
  galat: 'flex items-center gap-3 p-5 rounded-3xl border border-red-100 bg-red-50 text-red-700 text-sm font-bold',
  catatan: 'mx-4 mt-4 p-4 rounded-2xl bg-slate-50 text-sm text-slate-600',
  legend: 'px-6 py-4 text-[11px] font-bold text-slate-400',
  checkLabel: 'flex items-center gap-2 text-xs font-black text-slate-500',
  tagPerhatian: 'inline-block ml-2 text-[9px] font-black px-2 py-0.5 rounded-full bg-red-50 text-red-700 uppercase',
};

// ---------------------------------------------------------------------------
// P0 Komponen. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas dari scope modul bundle:
//   a = jsx runtime, ue = React, K$ = URL api/index.php, zi = kartu judul halaman,
//   ikon lucide: po (refresh-cw), Ss (printer), kp (arrow-left), Z1 (triangle-alert), D_ (graduation-cap).
// __pnkK = peta class Tailwind (KELAS di atas).
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */
function __pnkPantauNilaiKelas({ user, classId, semester, showClassPicker, activeSemester }) {
  const K = __pnkK;
  const API = K$;
  const h = (t, p, ...c) => {
    const { key, ...rest } = p || {};
    if (c.length) rest.children = c.length === 1 ? c[0] : c;
    return a.jsx(t, rest, key);
  };
  const normKelas = (v) => String(v == null ? '' : v).toLowerCase().replace(/[\s_.-]/g, '');

  const [kelasPilih, setKelasPilih] = ue.useState((user && user.class_homeroom) || '');
  const [semPilih, setSemPilih] = ue.useState(activeSemester === 'GENAP' ? '2' : '1');
  const [daftarKelas, setDaftarKelas] = ue.useState([]);
  const kelas = showClassPicker ? kelasPilih : classId;
  const sem = showClassPicker ? semPilih : (semester === '2' || semester === 'GENAP' ? '2' : '1');

  const [data, setData] = ue.useState(null);
  const [memuat, setMemuat] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const [tampilan, setTampilan] = ue.useState({ jenis: 'rekap' });
  const [detail, setDetail] = ue.useState(null);
  const [memuatDetail, setMemuatDetail] = ue.useState(false);
  const [galatDetail, setGalatDetail] = ue.useState('');
  const [selAktif, setSelAktif] = ue.useState(null);
  const [hanyaPerhatian, setHanyaPerhatian] = ue.useState(false);
  const [cari, setCari] = ue.useState('');
  const [urut, setUrut] = ue.useState({ kunci: 'default', naik: false });
  const permintaan = ue.useRef(0);

  const ambilJson = async (url) => {
    const res = await fetch(url);
    let j = null;
    try { j = await res.json(); } catch (e) { j = null; }
    if (!res.ok) {
      const pesan = j && j.error ? j.error : `Gagal memuat data (HTTP ${res.status}).`;
      throw new Error(pesan === 'Unauthorized' ? 'Sesi berakhir, silakan login ulang.' : pesan);
    }
    return j;
  };

  // Daftar kelas untuk pemilih (halaman BK)
  ue.useEffect(() => {
    if (!showClassPicker) return undefined;
    let batal = false;
    ambilJson(`${API}?action=get_classes`).then((j) => {
      if (batal) return;
      const list = Array.isArray(j) ? j : [];
      setDaftarKelas(list);
      setKelasPilih((k) => {
        const cocok = k ? list.find((c) => normKelas(c.id) === normKelas(k) || normKelas(c.name) === normKelas(k)) : null;
        if (cocok) return cocok.id;
        return list.length ? list[0].id : '';
      });
    }).catch(() => {});
    return () => { batal = true; };
  }, [showClassPicker]);

  const muat = ue.useCallback(async () => {
    const id = ++permintaan.current;
    if (!kelas) { setData(null); setGalat(''); return; }
    setMemuat(true);
    setGalat('');
    try {
      const j = await ambilJson(`${API}?action=get_class_grade_overview&class_id=${encodeURIComponent(kelas)}&semester=${sem}`);
      if (id === permintaan.current) setData(j);
    } catch (e) {
      if (id === permintaan.current) { setData(null); setGalat(e.message); }
    } finally {
      if (id === permintaan.current) setMemuat(false);
    }
  }, [kelas, sem]);

  ue.useEffect(() => { setTampilan({ jenis: 'rekap' }); muat(); }, [muat]);

  // Detail per pertemuan satu mapel
  ue.useEffect(() => {
    if (tampilan.jenis !== 'mapel' || !kelas) return undefined;
    let batal = false;
    setMemuatDetail(true);
    setGalatDetail('');
    setDetail(null);
    setSelAktif(null);
    ambilJson(`${API}?action=get_class_subject_grade_detail&class_id=${encodeURIComponent(kelas)}&subject_id=${encodeURIComponent(tampilan.id)}&semester=${sem}`)
      .then((j) => { if (!batal) setDetail(j); })
      .catch((e) => { if (!batal) setGalatDetail(e.message); })
      .finally(() => { if (!batal) setMemuatDetail(false); });
    return () => { batal = true; };
  }, [tampilan, kelas, sem]);

  const fmt = (v) => (v === null || v === undefined ? '–' : String(Math.round(v * 10) / 10).replace('.', ','));
  const tgl = (d) => { const p = String(d || '').slice(0, 10).split('-'); return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : '-'; };
  const tglPendek = (d) => { const p = String(d || '').slice(0, 10).split('-'); return p.length === 3 ? `${p[2]}/${p[1]}` : '-'; };
  const PRESENSI = { HADIR: 'Hadir', SAKIT: 'Sakit', IZIN: 'Izin', ALFA: 'Alfa' };
  const presensi = (s) => (s ? (PRESENSI[s] || s) : '–');
  const STATUS = { TUNTAS: 'Tuntas', REMEDI: 'Remedi', BELUM_LENGKAP: 'Belum lengkap' };
  const kelasSel = (g) => (!g || g.ref_score === null ? K.selKosong : g.below_kkm ? K.selMerah : g.ref_is_provisional ? K.selSementara : K.selHijau);
  const namaGuru = (s) => (s.teachers && s.teachers.length ? s.teachers.map((t) => t.name).join(', ') : 'Guru belum tercatat');
  const cetak = (sid) => window.open(`${API}?action=cetak_rekap_nilai_siswa&student_id=${encodeURIComponent(sid)}&semester=${sem}`, '_blank');

  const baris = ue.useMemo(() => {
    if (!data || !Array.isArray(data.students)) return [];
    const q = cari.trim().toLowerCase();
    const r = data.students.filter((s) => (!hanyaPerhatian || s.needs_attention)
      && (!q || String(s.student_name).toLowerCase().includes(q) || String(s.nisn || '').includes(q)));
    const nama = (x, y) => String(x.student_name).localeCompare(String(y.student_name), 'id');
    const k = urut.kunci;
    const nilai = (s) => {
      if (k === 'tertinggal') return s.total_missing;
      if (k === 'kkm') return s.below_kkm_count;
      const g = s.grades && s.grades[k];
      return g && g.ref_score !== null ? g.ref_score : null;
    };
    return r.slice().sort((x, y) => {
      if (k === 'default') return (Number(y.needs_attention) - Number(x.needs_attention)) || (y.total_missing - x.total_missing) || nama(x, y);
      if (k === 'nama') return urut.naik ? nama(x, y) : -nama(x, y);
      const vx = nilai(x); const vy = nilai(y);
      if (vx === null && vy === null) return nama(x, y);
      if (vx === null) return 1;
      if (vy === null) return -1;
      return ((vx - vy) * (urut.naik ? 1 : -1)) || nama(x, y);
    });
  }, [data, hanyaPerhatian, cari, urut]);

  const gantiUrut = (kunci) => setUrut((u) => (u.kunci === kunci
    ? { kunci, naik: !u.naik }
    : { kunci, naik: kunci !== 'tertinggal' && kunci !== 'kkm' }));
  const panah = (kunci) => (urut.kunci === kunci ? (urut.naik ? ' ▲' : ' ▼') : '');

  const galatBox = (pesan) => h('div', { className: K.galat }, h(Z1, { size: 18 }), h('span', null, pesan));
  const spinner = (teks) => h('div', { className: K.spinnerWrap }, h('div', { className: K.spinner }), h('p', { className: K.spinnerTeks }, teks));
  const tombolKembali = h('button', { onClick: () => setTampilan({ jenis: 'rekap' }), className: K.btnPutih }, h(kp, { size: 13 }), ' Kembali ke rekap');

  // ------------------------------------------------------------------ kontrol atas
  const kontrol = h('div', { className: K.bar },
    h('div', { className: K.barKiri },
      showClassPicker && h('select', { className: K.select, value: kelasPilih, onChange: (e) => setKelasPilih(e.target.value) },
        daftarKelas.length === 0 && h('option', { value: '' }, 'Memuat kelas...'),
        daftarKelas.map((c) => h('option', { key: c.id, value: c.id }, c.name || c.id))),
      showClassPicker && h('div', { className: K.toggleWrap },
        h('button', { onClick: () => setSemPilih('1'), className: semPilih === '1' ? K.toggleOn : K.toggleOff }, 'Smt Ganjil'),
        h('button', { onClick: () => setSemPilih('2'), className: semPilih === '2' ? K.toggleOn : K.toggleOff }, 'Smt Genap')),
      data && h('span', { className: K.periode }, `TA ${data.academic_year} · Semester ${data.semester === 'GENAP' ? 'Genap' : 'Ganjil'}`)),
    h('button', { onClick: muat, className: K.btnPutih }, h(po, { size: 13 }), ' Perbarui Nilai'));

  // ------------------------------------------------------------------ isi
  let isi;
  if (!kelas) {
    isi = h('div', { className: K.card }, h('p', { className: K.kosong }, showClassPicker ? 'Pilih kelas untuk melihat nilai.' : 'Anda belum terdaftar sebagai wali kelas.'));
  } else if (memuat && !data) {
    isi = spinner('Memuat nilai kelas...');
  } else if (galat) {
    isi = galatBox(galat);
  } else if (!data) {
    isi = null;
  } else if (tampilan.jenis === 'siswa') {
    const s = data.students.find((x) => x.student_id === tampilan.id);
    if (!s) {
      isi = h('div', { className: K.wrap }, tombolKembali, h('div', { className: K.card }, h('p', { className: K.kosong }, 'Siswa tidak ditemukan pada data kelas ini.')));
    } else {
      isi = h('div', { className: K.wrap },
        h('div', { className: K.bar }, tombolKembali,
          h('button', { onClick: () => cetak(s.student_id), className: K.btnHijau }, h(Ss, { size: 13 }), ' Cetak Laporan Nilai')),
        h('div', { className: K.card },
          h('div', { className: K.cardHead },
            h('div', null,
              h('p', { className: K.subjudul }, s.student_name, s.needs_attention && h('span', { className: K.tagPerhatian }, 'Perlu perhatian')),
              h('p', { className: K.teks }, `NISN ${s.nisn || '-'} · ${s.total_missing} tugas/kuis belum ada nilai · ${s.below_kkm_count} mapel di bawah KKM ${data.kkm}`))),
          h('div', { className: K.tabelWrap },
            h('table', { className: K.tabel },
              h('thead', { className: K.thead }, h('tr', null,
                h('th', { className: K.th }, 'Mata Pelajaran'),
                h('th', { className: K.thTengah }, 'Rata Tugas'),
                h('th', { className: K.thTengah }, 'Rata Kuis'),
                h('th', { className: K.thTengah }, 'UTS'),
                h('th', { className: K.thTengah }, 'UAS'),
                h('th', { className: K.thTengah }, 'Nilai Akhir'),
                h('th', { className: K.thTengah }, 'Tertinggal'),
                h('th', { className: K.th }, 'Keterangan'))),
              h('tbody', { className: K.tbody }, data.subjects.map((m) => {
                const g = s.grades[m.subject_id] || {};
                const miss = (g.missing_tugas || 0) + (g.missing_kuis || 0);
                const ket = m.status === 'BELUM_ADA_PENILAIAN' ? 'Belum ada penilaian'
                  : (STATUS[g.status] || g.status) + (g.ref_is_provisional ? ` (sementara ${fmt(g.ref_score)})` : '');
                return h('tr', { key: m.subject_id, className: K.tr },
                  h('td', { className: K.td }, h('span', { className: K.selBaris }, m.subject_name), h('span', { className: K.selKet }, namaGuru(m))),
                  h('td', { className: K.tdTengah }, g.avg_tugas === null ? '–' : `${fmt(g.avg_tugas)} (${g.tugas_count})`),
                  h('td', { className: K.tdTengah }, g.avg_kuis === null ? '–' : `${fmt(g.avg_kuis)} (${g.kuis_count})`),
                  h('td', { className: K.tdTengah }, fmt(g.ujian_uts)),
                  h('td', { className: K.tdTengah }, fmt(g.ujian_uas)),
                  h('td', { className: K.tdTengah }, h('span', { className: kelasSel(g) }, fmt(g.final_score !== null ? g.final_score : g.ref_score))),
                  h('td', { className: K.tdTengah }, miss > 0 ? h('span', { className: K.badgeMerah }, miss) : '–'),
                  h('td', { className: K.td }, ket));
              }))))),
        h('div', { className: K.card },
          h('div', { className: K.cardHead }, h('p', { className: K.judulKecil }, 'Tugas/Kuis yang Belum Ada Nilainya')),
          s.missing.length === 0 ? h('p', { className: K.kosong }, 'Nihil — semua tugas/kuis yang dinilai sudah ada nilainya.')
            : h('div', { className: K.tabelWrap },
              h('table', { className: K.tabel },
                h('thead', { className: K.thead }, h('tr', null,
                  h('th', { className: K.th }, 'Tanggal'), h('th', { className: K.th }, 'Mata Pelajaran'), h('th', { className: K.th }, 'Topik'),
                  h('th', { className: K.thTengah }, 'Jenis'), h('th', { className: K.thTengah }, 'Presensi'), h('th', { className: K.th }, 'Catatan Guru'))),
                h('tbody', { className: K.tbody }, s.missing.map((m, i) => h('tr', { key: `${m.journal_id}-${m.type}-${i}`, className: K.tr },
                  h('td', { className: K.td }, tgl(m.date)),
                  h('td', { className: K.td }, h('span', { className: K.selBaris }, m.subject_name), h('span', { className: K.selKet }, m.teacher_name || '')),
                  h('td', { className: K.td }, m.topic || '-'),
                  h('td', { className: K.tdTengah }, h('span', { className: m.type === 'TUGAS' ? K.badgeKuning : K.badgeAbu }, m.type === 'TUGAS' ? 'Tugas' : 'Kuis')),
                  h('td', { className: K.tdTengah }, presensi(m.attendance_status)),
                  h('td', { className: K.td }, m.notes || '-'))))))));
    }
  } else if (tampilan.jenis === 'mapel') {
    const m = data.subjects.find((x) => x.subject_id === tampilan.id) || { subject_id: tampilan.id, subject_name: tampilan.id, teachers: [] };
    let badan;
    if (memuatDetail) badan = spinner('Memuat nilai per pertemuan...');
    else if (galatDetail) badan = galatBox(galatDetail);
    else if (!detail) badan = null;
    else {
      const rapat = detail.meetings || [];
      badan = h('div', { className: K.card },
        h('div', { className: K.cardHead },
          h('div', null,
            h('p', { className: K.subjudul }, detail.subject.subject_name),
            h('p', { className: K.teks }, `${namaGuru(detail.subject)} · ${rapat.length} pertemuan bernilai · KKM ${detail.kkm}`))),
        selAktif && h('div', { className: K.catatan },
          h('b', null, `${selAktif.nama} · ${tgl(selAktif.rapat.date)} · ${selAktif.rapat.topic || '-'}`),
          h('br', null),
          `Presensi: ${presensi(selAktif.sel.attendance_status)} · Tugas: ${selAktif.rapat.has_tugas ? fmt(selAktif.sel.tugas_score) : 'tidak dinilai'} · Kuis: ${selAktif.rapat.has_kuis ? fmt(selAktif.sel.kuis_score) : 'tidak dinilai'}`,
          h('br', null),
          `Catatan guru: ${selAktif.sel.notes || '-'}`),
        rapat.length === 0 && h('p', { className: K.kosong }, 'Belum ada pertemuan bernilai untuk mapel ini pada semester ini.'),
        h('div', { className: K.tabelWrap },
          h('table', { className: K.tabel },
            h('thead', { className: K.thead }, h('tr', null,
              h('th', { className: K.th }, 'Nama Siswa'),
              rapat.map((r) => h('th', { key: r.journal_id, className: K.thTengah, title: `${tgl(r.date)} · ${r.topic || '-'} · ${r.teacher_name || ''}` },
                tglPendek(r.date), h('span', { className: K.selKet }, [r.has_tugas ? 'T' : '', r.has_kuis ? 'K' : ''].filter(Boolean).join('/') || '–'))),
              h('th', { className: K.thTengah }, 'UTS'),
              h('th', { className: K.thTengah }, 'UAS'),
              h('th', { className: K.thTengah }, 'Akhir'))),
            h('tbody', { className: K.tbody }, (detail.students || []).map((s) => h('tr', { key: s.student_id, className: K.tr },
              h('td', { className: K.tdNama }, h('span', { className: K.selBaris }, s.student_name)),
              rapat.map((r, i) => {
                const c = s.cells[i] || {};
                const bagian = [];
                if (r.has_tugas) bagian.push(h('span', { key: 't', className: c.missing_tugas ? K.selBarisMerah : K.selBaris }, `T ${fmt(c.tugas_score)}`));
                if (r.has_kuis) bagian.push(h('span', { key: 'k', className: c.missing_kuis ? K.selBarisMerah : K.selBaris }, `K ${fmt(c.kuis_score)}`));
                if (!bagian.length) bagian.push(h('span', { key: 'x', className: K.selKet }, '·'));
                const ket = [c.attendance_status && c.attendance_status !== 'HADIR' ? presensi(c.attendance_status) : '', c.notes ? '📝' : ''].filter(Boolean).join(' ');
                if (ket) bagian.push(h('span', { key: 'ket', className: K.selKet }, ket));
                return h('td', { key: r.journal_id, className: K.tdTengah },
                  h('button', { className: K.selBtn, title: c.notes || '', onClick: () => setSelAktif({ nama: s.student_name, rapat: r, sel: c }) }, bagian));
              }),
              h('td', { className: K.tdTengah }, fmt(s.summary.ujian_uts)),
              h('td', { className: K.tdTengah }, fmt(s.summary.ujian_uas)),
              h('td', { className: K.tdTengah }, h('span', { className: kelasSel(s.summary) }, fmt(s.summary.final_score !== null ? s.summary.final_score : s.summary.ref_score)))))))),
        h('p', { className: K.legend }, 'T = tugas, K = kuis. Angka merah = belum ada nilai padahal siswa lain sudah dinilai. 📝 = ada catatan guru. Klik sel untuk melihat rincian.'));
    }
    isi = h('div', { className: K.wrap },
      h('div', { className: K.bar }, tombolKembali, h('span', { className: K.periode }, m.subject_name)),
      badan);
  } else {
    const ringkas = data.summary || {};
    const kartu = (label, nilai, merah) => h('div', { className: K.stat },
      h('p', { className: K.statLabel }, label), h('p', { className: merah ? K.statNilaiMerah : K.statNilai }, nilai));
    isi = h('div', { className: K.wrap },
      h('div', { className: K.statGrid },
        kartu('Siswa Aktif', ringkas.student_count || 0, false),
        kartu('Perlu Perhatian', ringkas.needs_attention_count || 0, (ringkas.needs_attention_count || 0) > 0),
        kartu('Tugas/Kuis Tertinggal', ringkas.total_missing || 0, (ringkas.total_missing || 0) > 0),
        kartu('Mapel Belum Dinilai', ringkas.subjects_without_data || 0, false)),
      h('div', { className: K.card },
        h('div', { className: K.cardHead }, h('p', { className: K.judulKecil }, `Mata Pelajaran — Kelas ${data.class.name || data.class.id}`)),
        data.subjects.length === 0 ? h('p', { className: K.kosong }, 'Belum ada mapel yang ditugaskan atau dinilai di kelas ini.')
          : h('div', { className: K.mapelGrid }, data.subjects.map((m) => h('button', { key: m.subject_id, className: K.mapelBtn, onClick: () => setTampilan({ jenis: 'mapel', id: m.subject_id }) },
            h('p', { className: K.mapelNama }, m.subject_name),
            h('p', { className: K.mapelInfo }, namaGuru(m)),
            h('p', { className: K.mapelInfo }, m.status === 'BELUM_ADA_PENILAIAN'
              ? h('span', { className: K.badgeAbu }, 'Belum ada penilaian')
              : `${m.tugas_meetings} pertemuan tugas · ${m.kuis_meetings} kuis · ${m.has_ujian ? 'ada nilai ujian' : 'belum ada ujian'}`))))),
      h('div', { className: K.card },
        h('div', { className: K.cardHead },
          h('p', { className: K.judulKecil }, 'Rekap Nilai Siswa'),
          h('div', { className: K.barKiri },
            h('input', { className: K.input, placeholder: 'Cari nama / NISN', value: cari, onChange: (e) => setCari(e.target.value) }),
            h('label', { className: K.checkLabel },
              h('input', { type: 'checkbox', checked: hanyaPerhatian, onChange: (e) => setHanyaPerhatian(e.target.checked) }),
              'Hanya yang perlu perhatian'),
            urut.kunci !== 'default' && h('button', { className: K.btnPutih, onClick: () => setUrut({ kunci: 'default', naik: false }) }, 'Urutan awal'))),
        data.students.length === 0 ? h('p', { className: K.kosong }, 'Belum ada siswa aktif di kelas ini.')
          : baris.length === 0 ? h('p', { className: K.kosong }, 'Tidak ada siswa yang cocok dengan filter.')
          : h('div', { className: K.tabelWrap },
            h('table', { className: K.tabel },
              h('thead', { className: K.thead }, h('tr', null,
                h('th', { className: K.thKlik, onClick: () => gantiUrut('nama') }, 'Nama Siswa' + panah('nama')),
                data.subjects.map((m) => h('th', { key: m.subject_id, className: K.thTengahKlik, title: `${m.subject_name} — klik untuk mengurutkan`, onClick: () => gantiUrut(m.subject_id) },
                  (m.subject_id.length <= 8 ? m.subject_id : m.subject_name.slice(0, 8)) + panah(m.subject_id))),
                h('th', { className: K.thTengahKlik, onClick: () => gantiUrut('tertinggal') }, 'Tertinggal' + panah('tertinggal')),
                h('th', { className: K.thTengahKlik, onClick: () => gantiUrut('kkm') }, `< KKM${panah('kkm')}`))),
              h('tbody', { className: K.tbody }, baris.map((s) => h('tr', { key: s.student_id, className: K.tr },
                h('td', { className: K.tdNama },
                  h('button', { className: K.namaBtn, onClick: () => setTampilan({ jenis: 'siswa', id: s.student_id }) }, s.student_name),
                  s.needs_attention && h('span', { className: K.tagPerhatian }, 'Perhatian'),
                  h('span', { className: K.nisn }, s.nisn ? `NISN ${s.nisn}` : '')),
                data.subjects.map((m) => {
                  const g = s.grades[m.subject_id] || {};
                  const miss = (g.missing_tugas || 0) + (g.missing_kuis || 0);
                  return h('td', { key: m.subject_id, className: K.tdTengah, title: g.ref_is_provisional ? 'Sementara: belum ada nilai ujian' : '' },
                    h('span', { className: kelasSel(g) }, fmt(g.ref_score)),
                    miss > 0 && h('span', { className: K.miss }, `${miss} tertinggal`));
                }),
                h('td', { className: K.tdTengah }, s.total_missing > 0 ? h('span', { className: K.badgeMerah }, s.total_missing) : '–'),
                h('td', { className: K.tdTengah }, s.below_kkm_count > 0 ? h('span', { className: K.badgeMerah }, s.below_kkm_count) : '–')))))),
        h('p', { className: K.legend },
          `Nilai = nilai akhir (tugas ${data.weights.bobot_tugas}% + kuis ${data.weights.bobot_kuis}% + ujian ${data.weights.bobot_ujian}%). `
          + `Kuning = sementara (belum ada ujian, rata-rata tugas & kuis). Merah = di bawah KKM ${data.kkm}. `
          + '"Tertinggal" = pertemuan yang sudah dinilai guru, tetapi siswa ini belum punya nilai. Klik nama siswa untuk rincian dan cetak, klik mapel untuk nilai per pertemuan.')));
  }

  const konten = h('div', { className: K.wrap }, kontrol, isi);
  if (!showClassPicker) return konten;
  return h('div', { className: K.halaman },
    h(zi, {
      title: 'Pantau Nilai Kelas',
      description: 'Pantau nilai tugas, kuis, dan ujian semua mata pelajaran per kelas untuk mendampingi siswa yang tertinggal.',
      category: 'Bimbingan & Konseling',
      color: 'blue',
      illustration: h(D_, { size: 70, className: 'text-white/80' }),
    }),
    konten);
}
/* eslint-enable no-undef */

const KOMPONEN_SRC = `const __pnkK=${JSON.stringify(KELAS)};${__pnkPantauNilaiKelas.toString()}`;

const patches = [
  {
    id: 'P0 komponen PantauNilaiKelas',
    from: 'const K$=nr;function dot(',
    to: `const K$=nr;${KOMPONEN_SRC}function dot(`,
  },
  {
    id: 'P1 tab Nilai di Dashboard Kelas Saya',
    from: '[{id:"overview",label:"📊 Rekap Bulanan"},{id:"students",label:"👥 Per Siswa"},{id:"incidents",label:"⚠️ Insiden"}]',
    to: '[{id:"overview",label:"📊 Rekap Bulanan"},{id:"students",label:"👥 Per Siswa"},{id:"incidents",label:"⚠️ Insiden"},{id:"grades",label:"📝 Nilai"}]',
  },
  {
    id: 'P2 render tab Nilai',
    from: 'N==="overview"&&a.jsxs(',
    to: 'N==="grades"&&a.jsx(__pnkPantauNilaiKelas,{user:e,classId:r,semester:b}),N==="overview"&&a.jsxs(',
  },
  {
    id: 'P3 menu sidebar BK',
    from: 'a.jsx(Ox,{icon:a.jsx(S4,{size:20}),label:"Insiden BK",active:t==="bk-incidents",onClick:()=>n("bk-incidents"),collapsed:i})',
    to: 'a.jsx(Ox,{icon:a.jsx(S4,{size:20}),label:"Insiden BK",active:t==="bk-incidents",onClick:()=>n("bk-incidents"),collapsed:i}),'
      + 'a.jsx(Ox,{icon:a.jsx(D_,{size:20}),label:"Pantau Nilai Kelas",active:t==="bk-grades",onClick:()=>n("bk-grades"),collapsed:i})',
  },
  {
    // Blok BK dulu menempel di grup "Kepegawaian"; grup itu hilang sejak patch hapus presensi pegawai
    // sehingga menu BK (termasuk "Insiden BK") ikut hilang. Tempelkan ke grup pertama (selalu ada).
    id: 'P3b blok BK tidak bergantung pada grup Kepegawaian',
    from: 'Y.title==="Kepegawaian"&&M&&a.jsxs("div",{className:"space-y-1 mb-6"',
    to: 'ne===0&&M&&a.jsxs("div",{className:"space-y-1 mb-6"',
  },
  {
    id: 'P4 router halaman bk-grades',
    from: 'e==="bk-incidents"&&a.jsx(hot,{user:o}),',
    to: 'e==="bk-incidents"&&a.jsx(hot,{user:o}),e==="bk-grades"&&a.jsx(__pnkPantauNilaiKelas,{user:o,showClassPicker:!0,activeSemester:Ee}),',
  },
  {
    id: 'P5 daftar halaman dikenal',
    from: '"classroom-dashboard","bk-incidents","kepegawaian-gtk"',
    to: '"classroom-dashboard","bk-incidents","bk-grades","kepegawaian-gtk"',
  },
];

// ---------------------------------------------------------------------------
// Semua class Tailwind yang disisipkan harus sudah ada di CSS terkompilasi.
const css = fs.readFileSync(cssPath, 'utf8');
const cssEscape = (c) => c.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
const kelas = new Set();
Object.values(KELAS).forEach((v) => v.split(/\s+/).filter(Boolean).forEach((c) => kelas.add(c)));
// class literal lain di dalam komponen (mis. ikon ilustrasi)
for (const m of __pnkPantauNilaiKelas.toString().matchAll(/className:\s*(['"`])([^'"`]*?)\1/g)) {
  m[2].split(/\s+/).filter((c) => c && !c.includes('${')).forEach((c) => kelas.add(c));
}
const hilang = [...kelas].filter((c) => {
  const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
  return !re.test(css);
});
if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));
console.log(`OK  ${kelas.size} class Tailwind tersedia di CSS bundle`);

// Identifier yang disisipkan tidak boleh sudah ada di bundle
for (const nama of ['__pnkK', '__pnkPantauNilaiKelas', '"bk-grades"']) {
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
  const ref = `./assets/${SRC_NAME}`;
  if (html.split(ref).length - 1 !== 1) fail(`app.html tidak mereferensikan ${ref} tepat 1 kali.`);
  fs.writeFileSync(appHtmlPath, html.replace(ref, `./assets/${OUT_NAME}`), 'utf8');
  console.log(`app.html diarahkan ke assets/${OUT_NAME}`);
}
