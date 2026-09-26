// Patch bundle frontend: Dispensasi Siswa.
// Dokumentasi: docs/patches/dispensasi-siswa.md
//  P0 Komponen __dspHalaman (kelola dispensasi) + hook __dspPakaiJurnal + peta class (__dspK),
//     disisipkan tepat sebelum komponen __pnkPantauNilaiKelas (patch Pantau Nilai).
//  P1 Form jurnal: pasang hook; perubahan status oleh guru ditandai manual.
//  P2 Form jurnal: tombol D (Dispensasi) di setiap siswa.
//  P3 Form jurnal: gaya tombol D (CSS bundle tidak punya peer-checked:bg-indigo-*).
//  P4 Form jurnal: keterangan dispensasi di bawah nama siswa.
//  P5 Form jurnal: kotak ringkasan Dispensasi.
//  P6 Menu Kesiswaan: item "Dispensasi Siswa" (wali kelas, Kepala Sekolah, Admin, TU).
//  P7 Menu Bimbingan & Konseling: item "Dispensasi Siswa" (Guru BK).
//  P8 Router halaman: dispensasi -> __dspHalaman.
//  P9 Daftar halaman yang dikenal: tambah dispensasi.
// Prasyarat: patch Portal Nilai (apply-portal-nilai-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-dispensasi-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-portalnilai1.js';
const OUT_NAME = 'index-CGWL69P7-dispensasi1.js';
const SRC_SHA256 = '964781e9bbba2d446f2f861560d780dc234bec48754d80817c9fc8c42bf57377';
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
// Semua class Tailwind (divalidasi terhadap CSS bundle di bawah).
// ---------------------------------------------------------------------------
const BADGE = 'inline-block text-[10px] font-black px-2 py-0.5 rounded-full';
const TOMBOL_JAM = 'w-8 h-8 rounded-lg flex items-center justify-center text-[10px] font-black transition-all shadow-sm';
const KELAS = {
  halaman: 'max-w-6xl mx-auto space-y-8 pb-20',
  wrap: 'space-y-6',
  bar: 'flex flex-wrap items-center justify-between gap-3',
  barKiri: 'flex flex-wrap items-center gap-2',
  select: 'px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-600',
  input: 'w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-600',
  inputKecil: 'w-20 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-600',
  inputTanggal: 'px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600',
  label: 'text-[10px] font-black text-slate-400 uppercase tracking-widest',
  field: 'space-y-1',
  grid2: 'grid grid-cols-1 md:grid-cols-2 gap-4',
  btnPutih: 'flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-500 hover:bg-slate-50 transition-all',
  btnHijau: 'flex items-center gap-2 px-4 py-2 bg-emerald-600 border border-emerald-600 rounded-xl text-xs font-black text-white hover:bg-emerald-700 transition-all',
  btnMerah: 'flex items-center gap-2 px-3 py-1.5 bg-white border border-red-200 rounded-xl text-[10px] font-black text-red-600 hover:bg-red-50 transition-all',
  btnMati: 'flex items-center gap-2 px-4 py-2 bg-slate-100 border border-slate-200 rounded-xl text-xs font-black text-slate-400 cursor-not-allowed',
  toggleWrap: 'flex bg-slate-100 p-1.5 rounded-2xl w-fit',
  toggleOn: 'px-4 py-2 rounded-xl text-xs font-black transition-all bg-white text-emerald-600 shadow-sm',
  toggleOff: 'px-4 py-2 rounded-xl text-xs font-black transition-all text-slate-400 hover:text-slate-600',
  card: 'bg-white rounded-[2.5rem] border border-slate-100 overflow-hidden shadow-sm',
  cardHead: 'p-6 border-b border-slate-50 flex flex-wrap items-center justify-between gap-3',
  cardBody: 'p-6 space-y-4',
  judulKecil: 'text-xs font-black text-slate-400 uppercase tracking-widest',
  subjudul: 'text-lg font-black text-slate-800',
  teks: 'text-sm text-slate-500',
  teksKecil: 'text-[11px] font-bold text-slate-400',
  tautan: 'text-xs font-black text-emerald-600 hover:underline',
  infoHijau: 'p-4 rounded-2xl bg-emerald-50 text-emerald-700 text-sm font-bold',
  daftarSiswa: 'max-h-[320px] overflow-y-auto border border-slate-100 rounded-2xl divide-y divide-slate-50',
  barisSiswa: 'flex items-center gap-3 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 cursor-pointer',
  checkLabel: 'flex items-center gap-2 text-xs font-black text-slate-500',
  tabelWrap: 'overflow-x-auto',
  tabel: 'w-full text-left',
  thead: 'bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest',
  th: 'px-4 py-3 whitespace-nowrap',
  tbody: 'divide-y divide-slate-50',
  tr: 'hover:bg-slate-50/50 transition-colors',
  td: 'px-4 py-3 text-sm text-slate-600',
  tdNama: 'px-4 py-3 text-sm font-bold text-slate-700 whitespace-nowrap',
  badgeUngu: BADGE + ' bg-indigo-50 text-indigo-700',
  badgeAbu: BADGE + ' bg-slate-100 text-slate-500',
  kosong: 'py-16 text-center text-slate-400 font-bold text-sm',
  spinnerWrap: 'py-20 text-center space-y-3',
  spinner: 'w-10 h-10 border-4 border-emerald-600 border-t-transparent rounded-full animate-spin mx-auto',
  galat: 'flex items-center gap-3 p-5 rounded-3xl border border-red-100 bg-red-50 text-red-700 text-sm font-bold',
  // Form jurnal
  jurnalKet: 'text-[10px] font-bold text-indigo-600',
  jamD: TOMBOL_JAM + ' bg-indigo-600 text-white',
  jamDMati: TOMBOL_JAM + ' bg-white text-slate-400',
};
// Class yang ditulis langsung di teks patch (bukan lewat __dspK)
const KELAS_PATCH = ['grid', 'grid-cols-3', 'md:grid-cols-5', 'gap-3', 'mt-4', 'bg-indigo-50', 'border-indigo-100', 'text-indigo-600', 'text-indigo-700'];

// ---------------------------------------------------------------------------
// P0 Komponen. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas dari scope modul bundle:
//   a = jsx runtime, ue = React, K$ = URL api/index.php, zi = kartu judul halaman,
//   ikon lucide: po (refresh-cw), Z1 (triangle-alert), zp (ikon Surat & Disposisi).
// __dspK = peta class Tailwind (KELAS di atas).
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */

// Hook form jurnal: ambil dispensasi yang mencakup kelas/tanggal/jam, isi status D otomatis
// untuk jurnal baru (hanya siswa yang masih HADIR bawaan dan belum diubah guru).
function __dspPakaiJurnal({ kelas, tanggal, jam, jumlahSiswa, edit, setPresensi }) {
  const [info, setInfo] = ue.useState({});
  const otomatis = ue.useRef({});
  const manual = ue.useRef({});

  ue.useEffect(() => { otomatis.current = {}; manual.current = {}; }, [kelas, tanggal]);

  ue.useEffect(() => {
    if (!kelas || !tanggal) { setInfo({}); return undefined; }
    let batal = false;
    const pewaktu = setTimeout(async () => {
      let items = {};
      try {
        const q = `class_id=${encodeURIComponent(kelas)}&date=${encodeURIComponent(tanggal)}&jam=${encodeURIComponent(jam || '')}`;
        const res = await fetch(`${K$}?action=dispensasi_jurnal&${q}`);
        const j = res.ok ? await res.json() : null;
        if (j && j.items && typeof j.items === 'object') items = j.items;
      } catch (e) { items = {}; }
      if (batal) return;
      setInfo(items);
      if (edit) return;
      const lama = otomatis.current;
      const baruOtomatis = {};
      setPresensi((p) => {
        const baru = { ...p };
        Object.keys(lama).forEach((sid) => {
          if (manual.current[sid] || baru[sid] !== 'DISPENSASI') return;
          if (items[sid]) baruOtomatis[sid] = true;
          else baru[sid] = 'HADIR';
        });
        Object.keys(items).forEach((sid) => {
          if (!manual.current[sid] && baru[sid] === 'HADIR') { baru[sid] = 'DISPENSASI'; baruOtomatis[sid] = true; }
        });
        return baru;
      });
      otomatis.current = baruOtomatis;
    }, 400);
    return () => { batal = true; clearTimeout(pewaktu); };
  }, [kelas, tanggal, jam, jumlahSiswa, edit]);

  return {
    info,
    manual: (sid) => { manual.current[sid] = true; },
  };
}

function __dspHalaman({ user }) {
  const K = __dspK;
  const API = K$;
  const h = (t, p, ...c) => {
    const { key, ...rest } = p || {};
    if (c.length) rest.children = c.length === 1 ? c[0] : c;
    return a.jsx(t, rest, key);
  };
  const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const kini = new Date();
  const awalBulan = iso(new Date(kini.getFullYear(), kini.getMonth(), 1));
  const akhirBulan = iso(new Date(kini.getFullYear(), kini.getMonth() + 1, 0));
  const tgl = (d) => { const p = String(d || '').slice(0, 10).split('-'); return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : '-'; };

  const ambilJson = async (url, opsi) => {
    const res = await fetch(url, opsi);
    let j = null;
    try { j = await res.json(); } catch (e) { j = null; }
    if (!res.ok) {
      const pesan = j && j.error ? j.error : `Gagal memuat data (HTTP ${res.status}).`;
      throw new Error(pesan === 'Unauthorized' ? 'Sesi berakhir, silakan login ulang.' : pesan);
    }
    return j;
  };
  const kirim = (action, body) => ambilJson(`${API}?action=${action}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });

  // --- Daftar ---
  const [dari, setDari] = ue.useState(awalBulan);
  const [sampai, setSampai] = ue.useState(akhirBulan);
  const [filterKelas, setFilterKelas] = ue.useState('');
  const [data, setData] = ue.useState(null);
  const [memuat, setMemuat] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');
  const [pesan, setPesan] = ue.useState('');
  const [daftarKelas, setDaftarKelas] = ue.useState([]);
  const permintaan = ue.useRef(0);

  const muat = ue.useCallback(async () => {
    const id = ++permintaan.current;
    setMemuat(true);
    setGalat('');
    try {
      const q = `&dari=${dari}&sampai=${sampai}${filterKelas ? `&class_id=${encodeURIComponent(filterKelas)}` : ''}`;
      const j = await ambilJson(`${API}?action=dispensasi_list${q}`);
      if (id === permintaan.current) setData(j);
    } catch (e) {
      if (id === permintaan.current) { setData(null); setGalat(e.message); }
    } finally {
      if (id === permintaan.current) setMemuat(false);
    }
  }, [dari, sampai, filterKelas]);
  ue.useEffect(() => { muat(); }, [muat]);

  const semuaKelas = !!(data && data.scope && data.scope.mode === 'ALL');
  const kelasWali = data && data.scope ? data.scope.class_id : null;
  ue.useEffect(() => {
    if (!semuaKelas) return undefined;
    let batal = false;
    ambilJson(`${API}?action=get_classes`).then((j) => { if (!batal) setDaftarKelas(Array.isArray(j) ? j : []); }).catch(() => {});
    return () => { batal = true; };
  }, [semuaKelas]);

  // --- Form tambah ---
  const formKosong = () => ({
    kelas: '', mulai: iso(new Date()), selesai: iso(new Date()), penuh: false, jamMulai: '', jamSelesai: '',
    kegiatan: '', nomor: '', surat: '', namaSurat: '',
  });
  const [bukaForm, setBukaForm] = ue.useState(false);
  const [form, setForm] = ue.useState(formKosong);
  const [siswa, setSiswa] = ue.useState([]);
  const [pilih, setPilih] = ue.useState({});
  const [cari, setCari] = ue.useState('');
  const [proses, setProses] = ue.useState(false);
  const [galatForm, setGalatForm] = ue.useState('');
  const ubah = (k, v) => setForm((f) => ({ ...f, [k]: v }));
  const kelasForm = semuaKelas ? form.kelas : (kelasWali || '');

  ue.useEffect(() => {
    setSiswa([]);
    setPilih({});
    if (!bukaForm || !kelasForm) return undefined;
    let batal = false;
    ambilJson(`${API}?action=dispensasi_siswa&class_id=${encodeURIComponent(kelasForm)}`)
      .then((j) => { if (!batal) setSiswa(Array.isArray(j.students) ? j.students : []); })
      .catch((e) => { if (!batal) setGalatForm(e.message); });
    return () => { batal = true; };
  }, [bukaForm, kelasForm]);

  const unggah = async (e) => {
    const berkas = e.target.files && e.target.files[0];
    e.target.value = '';
    if (!berkas) return;
    setProses(true);
    setGalatForm('');
    try {
      const fd = new FormData();
      fd.append('file', berkas);
      const j = await ambilJson(`${API}?action=dispensasi_unggah_surat`, { method: 'POST', body: fd });
      setForm((f) => ({ ...f, surat: j.path, namaSurat: berkas.name }));
    } catch (err) {
      setGalatForm(err.message);
    } finally {
      setProses(false);
    }
  };

  const terpilih = siswa.filter((s) => pilih[s.id]).map((s) => s.id);
  const simpan = async () => {
    setProses(true);
    setGalatForm('');
    try {
      await kirim('dispensasi_simpan', {
        student_ids: terpilih,
        tanggal_mulai: form.mulai,
        tanggal_selesai: form.selesai || form.mulai,
        sehari_penuh: form.penuh,
        jam_ke_mulai: form.penuh ? null : form.jamMulai,
        jam_ke_selesai: form.penuh ? null : (form.jamSelesai || form.jamMulai),
        kegiatan: form.kegiatan,
        nomor_surat: form.nomor,
        surat_path: form.surat,
      });
      setPesan(`Dispensasi ${terpilih.length} siswa tersimpan. Presensi harian dan pelanggaran pada tanggal tersebut sudah dihitung ulang.`);
      setForm((f) => ({ ...formKosong(), kelas: f.kelas }));
      setPilih({});
      setBukaForm(false);
      muat();
    } catch (err) {
      setGalatForm(err.message);
    } finally {
      setProses(false);
    }
  };

  const hapus = async (d) => {
    if (!window.confirm(`Hapus dispensasi ${d.student_name} (${d.kegiatan})? Presensi harian akan dihitung ulang.`)) return;
    setGalat('');
    try {
      await kirim('dispensasi_hapus', { id: d.id });
      setPesan('Dispensasi dihapus.');
      muat();
    } catch (err) {
      setGalat(err.message);
    }
  };

  const siswaTampil = siswa.filter((s) => !cari || String(s.name).toLowerCase().includes(cari.toLowerCase()));
  const semuaDipilih = siswaTampil.length > 0 && siswaTampil.every((s) => pilih[s.id]);
  const field = (label, isi) => h('div', { className: K.field }, h('p', { className: K.label }, label), isi);

  const formulir = bukaForm && h('div', { className: K.card },
    h('div', { className: K.cardHead },
      h('p', { className: K.subjudul }, 'Tambah Dispensasi'),
      h('button', { onClick: () => { setBukaForm(false); setGalatForm(''); }, className: K.btnPutih }, 'Tutup')),
    h('div', { className: K.cardBody },
      galatForm && h('div', { className: K.galat }, h(Z1, { size: 18 }), h('span', null, galatForm)),
      h('div', { className: K.grid2 },
        field('Kelas', semuaKelas
          ? h('select', { className: K.select, value: form.kelas, onChange: (e) => ubah('kelas', e.target.value) },
            h('option', { value: '' }, '-- Pilih kelas --'),
            daftarKelas.map((c) => h('option', { key: c.id, value: c.id }, c.name || c.id)))
          : h('p', { className: K.teks }, kelasWali || '-')),
        field('Nama kegiatan / alasan *', h('input', {
          className: K.input, value: form.kegiatan, maxLength: 255, placeholder: 'Contoh: Lomba OSN Matematika tingkat provinsi',
          onChange: (e) => ubah('kegiatan', e.target.value),
        })),
        field('Tanggal mulai', h('input', { type: 'date', className: K.input, value: form.mulai, onChange: (e) => ubah('mulai', e.target.value) })),
        field('Tanggal selesai', h('input', { type: 'date', className: K.input, value: form.selesai, min: form.mulai, onChange: (e) => ubah('selesai', e.target.value) })),
        field('Cakupan', h('div', { className: K.barKiri },
          h('div', { className: K.toggleWrap },
            h('button', { onClick: () => ubah('penuh', false), className: !form.penuh ? K.toggleOn : K.toggleOff }, 'Jam ke-'),
            h('button', { onClick: () => ubah('penuh', true), className: form.penuh ? K.toggleOn : K.toggleOff }, 'Sehari penuh')),
          !form.penuh && h('input', { type: 'number', min: 1, max: 20, className: K.inputKecil, placeholder: 'dari', value: form.jamMulai, onChange: (e) => ubah('jamMulai', e.target.value) }),
          !form.penuh && h('span', { className: K.teksKecil }, 's.d.'),
          !form.penuh && h('input', { type: 'number', min: 1, max: 20, className: K.inputKecil, placeholder: 'sampai', value: form.jamSelesai, onChange: (e) => ubah('jamSelesai', e.target.value) }))),
        field('Nomor surat', h('input', { className: K.input, value: form.nomor, maxLength: 100, placeholder: 'Opsional', onChange: (e) => ubah('nomor', e.target.value) })),
        field('Berkas surat (PDF/JPG/PNG, maks 5 MB)', h('div', { className: K.barKiri },
          h('input', { type: 'file', accept: '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png', disabled: proses, onChange: unggah, className: K.teksKecil }),
          form.surat && h('a', { href: form.surat, target: '_blank', rel: 'noopener noreferrer', className: K.tautan }, `✓ ${form.namaSurat || 'Surat terunggah'}`),
          form.surat && h('button', { onClick: () => setForm((f) => ({ ...f, surat: '', namaSurat: '' })), className: K.btnMerah }, 'Lepas')))),
      h('div', { className: K.bar },
        h('p', { className: K.label }, `Siswa (${terpilih.length} dipilih)`),
        h('div', { className: K.barKiri },
          h('input', { className: K.inputTanggal, placeholder: 'Cari nama...', value: cari, onChange: (e) => setCari(e.target.value) }),
          h('label', { className: K.checkLabel },
            h('input', {
              type: 'checkbox', checked: semuaDipilih,
              onChange: (e) => { const v = e.target.checked; setPilih((p) => { const b = { ...p }; siswaTampil.forEach((s) => { b[s.id] = v; }); return b; }); },
            }), 'Pilih semua'))),
      !kelasForm ? h('p', { className: K.teksKecil }, 'Pilih kelas terlebih dahulu.')
        : h('div', { className: K.daftarSiswa },
          siswaTampil.length === 0 ? h('p', { className: K.kosong }, 'Tidak ada siswa.')
            : siswaTampil.map((s) => h('label', { key: s.id, className: K.barisSiswa },
              h('input', { type: 'checkbox', checked: !!pilih[s.id], onChange: (e) => { const v = e.target.checked; setPilih((p) => ({ ...p, [s.id]: v })); } }),
              h('span', null, s.name),
              s.nisn && h('span', { className: K.teksKecil }, s.nisn)))),
      h('div', { className: K.bar },
        h('p', { className: K.teksKecil }, 'Jam pelajaran yang dicakup otomatis terisi D di jurnal guru. Jam yang terlanjur ditandai Alfa ikut dihitung dispensasi, sehingga tidak menjadi pelanggaran.'),
        h('button', {
          disabled: proses || terpilih.length === 0 || !form.kegiatan.trim(),
          onClick: simpan,
          className: proses || terpilih.length === 0 || !form.kegiatan.trim() ? K.btnMati : K.btnHijau,
        }, proses ? 'Menyimpan...' : `Simpan Dispensasi (${terpilih.length} siswa)`))));

  const items = data && Array.isArray(data.items) ? data.items : [];
  let daftar;
  if (memuat && !data) {
    daftar = h('div', { className: K.spinnerWrap }, h('div', { className: K.spinner }));
  } else if (galat && !data) {
    daftar = h('div', { className: K.galat }, h(Z1, { size: 18 }), h('span', null, galat));
  } else {
    daftar = h('div', { className: K.card },
      h('div', { className: K.cardHead },
        h('p', { className: K.judulKecil }, `Daftar Dispensasi (${items.length})`),
        h('div', { className: K.barKiri },
          semuaKelas && h('select', { className: K.select, value: filterKelas, onChange: (e) => setFilterKelas(e.target.value) },
            h('option', { value: '' }, 'Semua kelas'),
            daftarKelas.map((c) => h('option', { key: c.id, value: c.id }, c.name || c.id))),
          h('input', { type: 'date', className: K.inputTanggal, value: dari, onChange: (e) => setDari(e.target.value) }),
          h('span', { className: K.teksKecil }, 's.d.'),
          h('input', { type: 'date', className: K.inputTanggal, value: sampai, onChange: (e) => setSampai(e.target.value) }),
          h('button', { onClick: muat, className: K.btnPutih }, h(po, { size: 13 }), ' Perbarui'))),
      galat && h('div', { className: K.cardBody }, h('div', { className: K.galat }, h(Z1, { size: 18 }), h('span', null, galat))),
      items.length === 0 ? h('p', { className: K.kosong }, 'Belum ada dispensasi pada rentang tanggal ini.')
        : h('div', { className: K.tabelWrap },
          h('table', { className: K.tabel },
            h('thead', { className: K.thead }, h('tr', null,
              h('th', { className: K.th }, 'Tanggal'),
              h('th', { className: K.th }, 'Siswa'),
              h('th', { className: K.th }, 'Kelas'),
              h('th', { className: K.th }, 'Cakupan'),
              h('th', { className: K.th }, 'Kegiatan'),
              h('th', { className: K.th }, 'Surat'),
              h('th', { className: K.th }, 'Dicatat'),
              h('th', { className: K.th }, ''))),
            h('tbody', { className: K.tbody }, items.map((d) => h('tr', { key: d.id, className: K.tr },
              h('td', { className: K.tdNama }, d.tanggal_mulai === d.tanggal_selesai ? tgl(d.tanggal_mulai) : `${tgl(d.tanggal_mulai)} – ${tgl(d.tanggal_selesai)}`),
              h('td', { className: K.tdNama }, d.student_name),
              h('td', { className: K.td }, d.class_name),
              h('td', { className: K.td }, h('span', { className: d.jam_ke_mulai === null ? K.badgeUngu : K.badgeAbu }, d.cakupan)),
              h('td', { className: K.td }, d.kegiatan),
              h('td', { className: K.td },
                d.nomor_surat && h('p', null, d.nomor_surat),
                d.surat_path ? h('a', { href: d.surat_path, target: '_blank', rel: 'noopener noreferrer', className: K.tautan }, 'Lihat surat') : (!d.nomor_surat && '-')),
              h('td', { className: K.td }, h('p', null, d.dicatat_oleh_nama), h('p', { className: K.teksKecil }, tgl(d.created_at))),
              h('td', { className: K.td }, h('button', { onClick: () => hapus(d), className: K.btnMerah }, 'Hapus'))))))));
  }

  return h('div', { className: K.halaman },
    h(zi, {
      title: 'Dispensasi Siswa',
      description: 'Catat murid yang mengikuti kegiatan sekolah dengan surat dispensasi. Jam yang dicakup dihitung hadir dan tidak menjadi pelanggaran.',
      category: 'Kesiswaan',
      color: 'emerald',
      illustration: h(zp, { size: 70, className: 'text-white/80' }),
    }),
    h('div', { className: K.wrap },
      pesan && h('p', { className: K.infoHijau }, pesan),
      h('div', { className: K.bar },
        h('p', { className: K.teks }, data && !semuaKelas && kelasWali ? `Kelas perwalian: ${kelasWali}` : ''),
        !bukaForm && data && h('button', { onClick: () => { setBukaForm(true); setPesan(''); }, className: K.btnHijau }, '+ Tambah Dispensasi')),
      formulir,
      daftar));
}
/* eslint-enable no-undef */

const KOMPONEN_SRC = `const __dspK=${JSON.stringify(KELAS)};${__dspPakaiJurnal.toString()}${__dspHalaman.toString()}`;

const TOMBOL_JAM_ASLI = 'w-8 h-8 rounded-lg flex items-center justify-center text-[10px] font-black transition-all bg-white text-slate-400 peer-checked:text-white shadow-sm';

const patches = [
  {
    id: 'P0 komponen dispensasi',
    from: 'function __pnkPantauNilaiKelas(',
    to: `${KOMPONEN_SRC}function __pnkPantauNilaiKelas(`,
  },
  {
    id: 'P1 hook dispensasi di form jurnal',
    from: 'const Pn=(Ge,at)=>{R(Ke=>({...Ke,[Ge]:at}))}',
    to: 'const __dspJ=__dspPakaiJurnal({kelas:y.kelas,tanggal:y.tanggal,jam:y.jam,jumlahSiswa:ke.length,edit:!!n,setPresensi:R}),'
      + 'Pn=(Ge,at)=>{__dspJ.manual(Ge),R(Ke=>({...Ke,[Ge]:at}))}',
  },
  {
    id: 'P2 tombol D di form jurnal',
    from: '{val:"ALFA",label:"A",color:"peer-checked:bg-rose-500"}].map(Ke=>a.jsxs("label",{className:"relative group cursor-pointer"',
    to: '{val:"ALFA",label:"A",color:"peer-checked:bg-rose-500"},{val:"DISPENSASI",label:"D",color:""}].map(Ke=>a.jsxs("label",{title:Ke.val==="DISPENSASI"?"Dispensasi":void 0,className:"relative group cursor-pointer"',
  },
  {
    id: 'P3 gaya tombol D',
    from: `className:$e("${TOMBOL_JAM_ASLI}",Ke.color)`,
    to: `className:Ke.val==="DISPENSASI"?(T[Ge.id]==="DISPENSASI"?__dspK.jamD:__dspK.jamDMati):$e("${TOMBOL_JAM_ASLI}",Ke.color)`,
  },
  {
    id: 'P4 keterangan dispensasi di bawah nama siswa',
    from: 'children:at+1}),a.jsx("p",{className:"text-sm font-bold text-slate-700",children:Ge.name})',
    to: 'children:at+1}),a.jsxs("div",{children:[a.jsx("p",{className:"text-sm font-bold text-slate-700",children:Ge.name}),'
      + '__dspJ.info[Ge.id]&&a.jsx("p",{className:__dspK.jurnalKet,children:`Dispensasi: ${__dspJ.info[Ge.id].kegiatan} (${__dspJ.info[Ge.id].cakupan})`})]})',
  },
  {
    id: 'P5 ringkasan Dispensasi',
    from: 'a.jsx("div",{className:"grid grid-cols-4 gap-3 mt-4",children:[{label:"Hadir",val:"HADIR",c:"emerald"},{label:"Sakit",val:"SAKIT",c:"blue"},{label:"Izin",val:"IZIN",c:"amber"},{label:"Alfa",val:"ALFA",c:"rose"}]',
    to: 'a.jsx("div",{className:"grid grid-cols-3 md:grid-cols-5 gap-3 mt-4",children:[{label:"Hadir",val:"HADIR",c:"emerald"},{label:"Sakit",val:"SAKIT",c:"blue"},{label:"Izin",val:"IZIN",c:"amber"},{label:"Alfa",val:"ALFA",c:"rose"},{label:"Dispensasi",val:"DISPENSASI",c:"indigo"}]',
  },
  {
    id: 'P6 menu Kesiswaan Dispensasi Siswa',
    from: 'roles:["KEPALA_SEKOLAH","GURU"]},{id:"parent-call"',
    to: 'roles:["KEPALA_SEKOLAH","GURU"]},{id:"dispensasi",label:"Dispensasi Siswa",icon:a.jsx(zp,{size:20}),roles:["KEPALA_SEKOLAH","GURU","ADMIN","TATA_USAHA"]},{id:"parent-call"',
  },
  {
    id: 'P7 menu BK Dispensasi Siswa',
    from: 'label:"Pantau Nilai Kelas",active:t==="bk-grades",onClick:()=>n("bk-grades"),collapsed:i})',
    to: 'label:"Pantau Nilai Kelas",active:t==="bk-grades",onClick:()=>n("bk-grades"),collapsed:i}),'
      + 'a.jsx(Ox,{icon:a.jsx(zp,{size:20}),label:"Dispensasi Siswa",active:t==="dispensasi",onClick:()=>n("dispensasi"),collapsed:i})',
  },
  {
    id: 'P8 router halaman dispensasi',
    from: 'e==="portal-nilai"&&a.jsx(__ponHalamanAdmin,{}),',
    to: 'e==="portal-nilai"&&a.jsx(__ponHalamanAdmin,{}),e==="dispensasi"&&a.jsx(__dspHalaman,{user:o}),',
  },
  {
    id: 'P9 daftar halaman dikenal',
    from: '"portal-nilai","kepegawaian-gtk"',
    to: '"portal-nilai","dispensasi","kepegawaian-gtk"',
  },
];

// ---------------------------------------------------------------------------
const css = fs.readFileSync(cssPath, 'utf8');
const cssEscape = (c) => c.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
const kelas = new Set(KELAS_PATCH);
Object.values(KELAS).forEach((v) => v.split(/\s+/).filter(Boolean).forEach((c) => kelas.add(c)));
for (const m of (__dspPakaiJurnal.toString() + __dspHalaman.toString()).matchAll(/className:\s*(['"`])([^'"`]*?)\1/g)) {
  m[2].split(/\s+/).filter((c) => c && !c.includes('${')).forEach((c) => kelas.add(c));
}
const hilang = [...kelas].filter((c) => {
  const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
  return !re.test(css);
});
if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));
console.log(`OK  ${kelas.size} class Tailwind tersedia di CSS bundle`);

for (const nama of ['__dspK', '__dspPakaiJurnal', '__dspHalaman', '"dispensasi"', '__dspJ']) {
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
