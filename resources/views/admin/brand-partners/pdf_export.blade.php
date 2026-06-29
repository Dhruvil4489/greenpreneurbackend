<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brand Partners Export Report</title>
    <!-- Use bootstrap from local or CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            background-color: #fff;
            color: #333;
            padding: 20px;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                padding: 0;
            }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="container-fluid">
        <!-- Report Header -->
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-0">Peers Global Unity</h3>
                <span class="text-muted small">Brand Partners Report — Exported on {{ now()->format('Y-m-d H:i') }}</span>
            </div>
            <button onclick="window.print()" class="btn btn-sm btn-primary no-print"><i class="bi bi-printer"></i> Print Report</button>
        </div>

        <!-- Report Table -->
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Brand Name</th>
                    <th>Slug</th>
                    <th>Category</th>
                    <th class="text-center">Featured</th>
                    <th class="text-center">Sponsored</th>
                    <th>Offer Title</th>
                    <th>Coupon</th>
                    <th>Status</th>
                    <th>Created At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($partners as $partner)
                    <tr>
                        <td class="small text-muted">{{ $partner->id }}</td>
                        <td class="fw-bold">{{ $partner->name }}</td>
                        <td>/{{ $partner->slug }}</td>
                        <td>{{ $partner->category?->name ?? '—' }}</td>
                        <td class="text-center">{{ $partner->is_featured ? 'Yes' : 'No' }}</td>
                        <td class="text-center">{{ $partner->is_sponsored ? 'Yes' : 'No' }}</td>
                        <td>{{ $partner->offer_title ?? '—' }}</td>
                        <td><code>{{ $partner->coupon_code ?? '—' }}</code></td>
                        <td>{{ $partner->is_active ? 'Active' : 'Inactive' }}</td>
                        <td>{{ $partner->created_at->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted">No partners to export.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
