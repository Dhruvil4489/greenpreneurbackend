@extends('admin.layouts.auth')

@section('title', 'Admin Login')

@section('content')
<div class="page-container auth-wrapper">
    <div class="card">
        <div class="card-header">
            <div class="text-center mb-3">
                <img
                    src="{{ asset('images/greenpreneur-logo.png') }}"
                    alt="Greenpreneur"
                    style="max-height:100px; width:auto;"
                    class="d-block mx-auto mb-4"
                    loading="lazy"
                />
            </div>
        </div>

        @if (session('status'))
            <div class="status show success" id="statusMessage">{{ session('status') }}</div>
        @elseif ($errors->any())
            <div class="status show error" id="statusMessage">{{ $errors->first() }}</div>
        @else
            <div id="statusMessage" class="status"></div>
        @endif

        <form id="request-otp-form" autocomplete="off" method="POST" action="{{ route('admin.login.send-otp') }}">
            @csrf
            <label for="email">Admin Email</label>
            <div class="input-row">
                <input id="email" name="email" type="email" placeholder="you@company.com" required autocomplete="email" value="{{ old('email', session('admin_login_email')) }}" />
                <button type="submit" class="btn primary" id="request-otp-btn">Send OTP</button>
            </div>
            <p class="muted">Only global admins are eligible. OTP codes expire in 5 minutes.</p>
        </form>

        <div style="height: 16px;"></div>

        <form id="verify-otp-form" autocomplete="off" method="POST" action="{{ route('admin.login.verify') }}">
            @csrf
            <input type="hidden" name="email" id="verify-email" value="{{ old('email', session('admin_login_email')) }}">
            <label for="otp-1">Verification Code</label>
            <div class="otp-grid">
                <input id="otp-1" class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" aria-label="OTP Digit 1">
                <input id="otp-2" class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" aria-label="OTP Digit 2">
                <input id="otp-3" class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" aria-label="OTP Digit 3">
                <input id="otp-4" class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" aria-label="OTP Digit 4">
            </div>
            <p class="muted">Enter the 4-digit OTP sent to your email.</p>
            <button type="submit" class="btn secondary" id="verify-otp-btn" style="margin-top: 10px;">Verify & Login</button>
        </form>
    </div>
</div>

<script>
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const requestForm = document.getElementById('request-otp-form');
    const verifyForm = document.getElementById('verify-otp-form');
    const statusMessage = document.getElementById('statusMessage');
    const emailInput = document.getElementById('email');
    const verifyEmailInput = document.getElementById('verify-email');
    const requestBtn = document.getElementById('request-otp-btn');
    const verifyBtn = document.getElementById('verify-otp-btn');
    const otpInputs = Array.from(document.querySelectorAll('.otp-input'));

    function setStatus(text, type = 'success') {
        if (!text) {
            statusMessage.className = 'status';
            statusMessage.textContent = '';
            return;
        }

        statusMessage.textContent = text;
        statusMessage.className = `status show ${type}`;
    }

    function setLoading(button, isLoading, loadingText) {
        if (isLoading) {
            button.dataset.originalText = button.textContent;
            button.textContent = loadingText;
            button.disabled = true;
        } else {
            button.textContent = button.dataset.originalText || button.textContent;
            button.disabled = false;
        }
    }

    function syncEmail() {
        verifyEmailInput.value = emailInput.value.trim();
    }

    emailInput.addEventListener('input', syncEmail);

    otpInputs.forEach((input, index) => {
        input.addEventListener('input', (event) => {
            const value = event.target.value.replace(/\D/g, '');
            event.target.value = value;

            if (value && index < otpInputs.length - 1) {
                otpInputs[index + 1].focus();
            }
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' && !event.target.value && index > 0) {
                otpInputs[index - 1].focus();
            }
        });
    });

    requestForm.addEventListener('submit', () => {
        syncEmail();
        setStatus('');
        setLoading(requestBtn, true, 'Sending...');
    });

    verifyForm.addEventListener('submit', () => {
        syncEmail();
        setStatus('');
        const otpValue = otpInputs.map((input) => input.value).join('');
        document.querySelector('[name=\"otp\"]')?.remove();
        const hiddenOtp = document.createElement('input');
        hiddenOtp.type = 'hidden';
        hiddenOtp.name = 'otp';
        hiddenOtp.value = otpValue;
        verifyForm.appendChild(hiddenOtp);
        setLoading(verifyBtn, true, 'Verifying...');
    });
</script>
@endsection
