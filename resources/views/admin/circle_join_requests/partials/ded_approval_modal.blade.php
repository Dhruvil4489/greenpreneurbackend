@once
<div class="modal fade" id="dedApprovalConfirmModal" tabindex="-1" aria-labelledby="dedApprovalConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dedApprovalConfirmModalLabel">Confirm DED Approval</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to approve this request?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">No</button>
                <button type="button" class="btn btn-warning" id="confirmDedApprovalSubmit">Yes</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modalElement = document.getElementById('dedApprovalConfirmModal');
    const confirmButton = document.getElementById('confirmDedApprovalSubmit');

    if (!modalElement || !confirmButton || typeof bootstrap === 'undefined') {
        return;
    }

    const modal = new bootstrap.Modal(modalElement);
    let pendingForm = null;

    document.querySelectorAll('form[data-ded-approval-form="true"]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            pendingForm = form;
            modal.show();
        });
    });

    confirmButton.addEventListener('click', () => {
        if (!pendingForm) {
            return;
        }

        const form = pendingForm;
        pendingForm = null;
        modal.hide();
        form.submit();
    });
});
</script>
@endpush
@endonce
