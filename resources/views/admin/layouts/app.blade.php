<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin Panel')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    @stack('styles')
</head>
<body>
    <div class="admin-shell d-flex">
        @include('admin.partials.sidebar')
        <div class="admin-main flex-grow-1">
            @include('admin.partials.topbar')
            <main class="admin-content container-fluid py-4">
                @yield('content')
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/admin-filters.js') }}"></script>
    @stack('scripts')

    <!-- Media Preview Modal -->
    <div class="modal fade" id="mediaPreviewModal" tabindex="-1" aria-labelledby="mediaPreviewModalLabel" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content shadow">
                <div class="modal-header">
                    <h5 class="modal-title" id="mediaPreviewModalLabel">
                        <i class="bi bi-file-earmark-medical me-2 text-primary"></i>Media Preview
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-0" style="min-height: 250px; display: flex; align-items: center; justify-content: center; background-color: #f8f9fa;">
                    <!-- Loader -->
                    <div id="mediaPreviewLoader" class="w-100 py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div class="text-muted small mt-2">Checking file availability...</div>
                    </div>
                    <!-- Image Container -->
                    <div class="w-100 p-3 d-none" id="mediaPreviewImageWrapper">
                        <img id="mediaPreviewImage" src="" class="img-fluid rounded border shadow-sm" alt="Preview" style="max-height: 65vh; object-fit: contain;">
                    </div>
                    <!-- PDF/Iframe Container -->
                    <iframe id="mediaPreviewIframe" src="" class="w-100 d-none" style="height: 65vh; border: none;"></iframe>
                    <!-- Error Alert -->
                    <div id="mediaPreviewError" class="p-4 w-100 d-none">
                        <div class="alert alert-danger d-inline-block mb-0 shadow-sm">
                            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> Media file is unavailable.
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between bg-light">
                    <a id="mediaPreviewDownloadBtn" href="" class="btn btn-primary btn-sm d-none" download>
                        <i class="bi bi-download me-1"></i> Download File
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm ms-auto" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Intercept clicks on links that reference files API
            document.addEventListener('click', function(e) {
                const link = e.target.closest('a[href*="/api/v1/files/"]');
                if (!link) return;
                
                // Skip if this click is inside the preview modal itself
                if (link.closest('#mediaPreviewModal')) return;

                e.preventDefault();
                const url = link.getAttribute('href');
                
                const modalEl = document.getElementById('mediaPreviewModal');
                const imgWrapper = document.getElementById('mediaPreviewImageWrapper');
                const img = document.getElementById('mediaPreviewImage');
                const iframe = document.getElementById('mediaPreviewIframe');
                const errorDiv = document.getElementById('mediaPreviewError');
                const loader = document.getElementById('mediaPreviewLoader');
                const downloadBtn = document.getElementById('mediaPreviewDownloadBtn');

                // Reset modal states
                imgWrapper.classList.add('d-none');
                img.setAttribute('src', '');
                iframe.classList.add('d-none');
                iframe.setAttribute('src', '');
                errorDiv.classList.add('d-none');
                loader.classList.remove('d-none');
                downloadBtn.classList.add('d-none');
                downloadBtn.setAttribute('href', '');
                
                // Show modal first
                const previewModal = new bootstrap.Modal(modalEl);
                previewModal.show();

                // Perform HEAD request to check file existence and get its mime type
                fetch(url, { method: 'HEAD' })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('File not found');
                        }
                        const contentType = response.headers.get('content-type') || '';
                        loader.classList.add('d-none');
                        downloadBtn.classList.remove('d-none');
                        downloadBtn.setAttribute('href', url);

                        if (contentType.startsWith('image/')) {
                            img.setAttribute('src', url);
                            imgWrapper.classList.remove('d-none');
                        } else if (contentType === 'application/pdf') {
                            iframe.setAttribute('src', url);
                            iframe.classList.remove('d-none');
                        } else {
                            // It's a document/other file, trigger download directly and close modal
                            previewModal.hide();
                            const dlLink = document.createElement('a');
                            dlLink.href = url;
                            dlLink.download = '';
                            document.body.appendChild(dlLink);
                            dlLink.click();
                            document.body.removeChild(dlLink);
                        }
                    })
                    .catch(error => {
                        loader.classList.add('d-none');
                        errorDiv.classList.remove('d-none');
                        console.error('File preview error:', error);
                    });
            });
        });
    </script>
</body>
</html>
