@extends('admin.layouts.app')

@section('title', 'Add Brand Partner')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Add Brand Partner</h1>
    <a href="{{ route('admin.brand-partners.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger shadow-sm">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('admin.brand-partners.store') }}" enctype="multipart/form-data">
    @csrf
    @include('admin.brand-partners._form')
    
    <div class="mt-3 card border-0 shadow-sm p-3">
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save Brand Partner</button>
            <a href="{{ route('admin.brand-partners.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>
@endsection
