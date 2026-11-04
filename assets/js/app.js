// FILE: assets/js/app.js

document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });

    // Notification dropdown toggle
    const notifBell = document.querySelector('.notification-bell');
    const notifDropdown = document.querySelector('.notification-dropdown');
    if (notifBell && notifDropdown) {
        notifBell.addEventListener('click', function(e) {
            e.stopPropagation();
            notifDropdown.classList.toggle('show');
        });
        document.addEventListener('click', function(e) {
            if (!notifDropdown.contains(e.target)) {
                notifDropdown.classList.remove('show');
            }
        });
    }

    // Mark notification as read
    document.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch(BASE_URL + 'modules/alerts/index.php?action=mark_read&id=' + id)
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        this.classList.remove('unread');
                        const badge = document.querySelector('.badge-count');
                        if (badge) {
                            let count = parseInt(badge.textContent) - 1;
                            if (count <= 0) badge.style.display = 'none';
                            else badge.textContent = count;
                        }
                    }
                });
        });
    });

    // File upload drag & drop
    const uploadArea = document.querySelector('.file-upload-area');
    const fileInput = document.querySelector('#file-input');
    if (uploadArea && fileInput) {
        uploadArea.addEventListener('click', () => fileInput.click());
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });
        uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            fileInput.files = e.dataTransfer.files;
            updateFileName();
        });
        fileInput.addEventListener('change', updateFileName);
    }

    function updateFileName() {
        const nameDisplay = document.querySelector('.file-name');
        if (fileInput.files.length > 0 && nameDisplay) {
            nameDisplay.textContent = fileInput.files[0].name;
        }
    }

    // Category change -> load metadata fields
    const categorySelect = document.querySelector('#category_id');
    if (categorySelect) {
        categorySelect.addEventListener('change', function() {
            loadMetadataFields(this.value);
        });
    }

    // Tab functionality
    document.querySelectorAll('.tab-link').forEach(tab => {
        tab.addEventListener('click', function() {
            const target = this.dataset.tab;
            document.querySelectorAll('.tab-link').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            document.getElementById(target)?.classList.add('active');
        });
    });

    // Modal
    document.querySelectorAll('[data-modal]').forEach(trigger => {
        trigger.addEventListener('click', function() {
            const modal = document.querySelector(this.dataset.modal);
            if (modal) modal.classList.add('show');
        });
    });
    document.querySelectorAll('.modal-close, .modal-overlay').forEach(el => {
        el.addEventListener('click', function(e) {
            if (e.target === this) {
                this.closest('.modal-overlay')?.classList.remove('show');
            }
        });
    });

        // Sidebar mobile toggle & overlay
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    // Buat elemen overlay dinamis
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);

    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', (e) => {
            e.preventDefault();
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
        });

        // Jika klik di luar sidebar (area gelap), tutup sidebar
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
        
        // Tutup otomatis saat link menu diklik (khusus HP)
        const sidebarLinks = sidebar.querySelectorAll('a');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('show');
                }
            });
        });
    }

    // Confirm delete
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (!confirm('Apakah Anda yakin ingin menghapus item ini? Tindakan ini tidak dapat dibatalkan.')) {
                e.preventDefault();
            }
        });
    });

    // Select all checkboxes
    const selectAll = document.querySelector('#select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
        });
    }

    // Tag input
    const tagInput = document.querySelector('#tag-input');
    if (tagInput) {
        tagInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addTag(this.value.trim());
                this.value = '';
            }
        });
    }
});

function loadMetadataFields(categoryId) {
    const container = document.querySelector('#metadata-fields');
    if (!container) return;
    if (!categoryId) {
        container.innerHTML = '';
        return;
    }
    fetch(BASE_URL + 'modules/categories/index.php?action=get_fields&category_id=' + categoryId)
        .then(r => r.json())
        .then(fields => {
            let html = '';
            fields.forEach(f => {
                const req = f.is_required ? 'required' : '';
                const reqLabel = f.is_required ? '<span class="text-danger">*</span>' : '';
                if (f.field_type === 'textarea') {
                    html += `<div class="form-group"><label>${f.field_label} ${reqLabel}</label><textarea name="meta[${f.id}]" class="form-control" ${req}></textarea></div>`;
                } else if (f.field_type === 'select') {
                    let opts = '<option value="">-- Pilih --</option>';
                    try {
                        JSON.parse(f.field_options).forEach(o => opts += `<option value="${o}">${o}</option>`);
                    } catch(e) {}
                    html += `<div class="form-group"><label>${f.field_label} ${reqLabel}</label><select name="meta[${f.id}]" class="form-control" ${req}>${opts}</select></div>`;
                } else {
                    html += `<div class="form-group"><label>${f.field_label} ${reqLabel}</label><input type="${f.field_type}" name="meta[${f.id}]" class="form-control" ${req}></div>`;
                }
            });
            container.innerHTML = html;
        })
        .catch(() => container.innerHTML = '<p class="text-danger">Gagal memuat metadata fields</p>');
}

function addTag(name) {
    if (!name) return;
    const container = document.querySelector('#selected-tags');
    const existing = container.querySelectorAll('.tag-item');
    for (let tag of existing) {
        if (tag.dataset.name === name) return;
    }
    const div = document.createElement('div');
    div.className = 'tag-item';
    div.dataset.name = name;
    div.style.background = '#dbeafe';
    div.style.color = '#2563eb';
    div.innerHTML = `${name}<input type="hidden" name="tags[]" value="${name}"><span class="remove-tag" onclick="this.parentElement.remove()">×</span>`;
    container.appendChild(div);
}

// Declare BASE_URL for JS usage
const BASE_URL = document.querySelector('meta[name="base-url"]')?.content || '/edams/';