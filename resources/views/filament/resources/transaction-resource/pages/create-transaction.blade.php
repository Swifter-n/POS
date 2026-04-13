@filamentScripts

@stack('scripts')

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Helper untuk membaca query parameter "step"
    function getStepFromQuery() {
        const match = location.search.match(/[?&]step=([^&]+)/);
        return match ? decodeURIComponent(match[1]) : null;
    }

    // Emit step awal (misalnya kalau reload halaman)
    const initialStep = getStepFromQuery();
    if (initialStep && window.Livewire) {
        Livewire.emit('wizardStepChanged', initialStep);
        console.log('[Wizard Sync] Initial step:', initialStep);
    }

    // Fungsi untuk mendeteksi step aktif di DOM
    function detectActiveStep() {
        const activeEl = document.querySelector('.fi-wizard-step[data-step][data-state="current"], .filament-wizard-step[data-step][data-state="current"]');
        if (activeEl) {
            return activeEl.getAttribute('data-step');
        }
        return getStepFromQuery();
    }

    // Fungsi untuk emit event ke Livewire
    function emitStepChanged() {
        const step = detectActiveStep();
        if (step && window.Livewire) {
            Livewire.emit('wizardStepChanged', step);
            console.log('[Wizard Sync] Changed step:', step);
        }
    }

    // Gunakan MutationObserver untuk pantau perubahan wizard
    const observer = new MutationObserver(() => {
        emitStepChanged();
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-state']
    });

    // Tambahkan listener klik (misal user klik tombol Next/Previous)
    document.addEventListener('click', function (e) {
        if (e.target.closest('.fi-wizard-next-button, .fi-wizard-previous-button, [data-step]')) {
            setTimeout(emitStepChanged, 200); // beri delay kecil agar DOM sempat update
        }
    });
});
</script>
