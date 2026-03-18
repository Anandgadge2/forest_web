{{-- Consolidated Dashboard Scripts for AJAX and Initial Load --}}

@php
    // Incident status map
    $statusMap = [
        0 => 'Pending (Supervisor)',
        1 => 'Resolved',
        2 => 'Ignored',
        3 => 'Escalated (Admin)',
        4 => 'Pending (Admin)',
        5 => 'Escalated (Client)',
        6 => 'Reverted'
    ];
    
    $statusLabels = []; $statusData = [];
    if (isset($incidentTracking['statusDistribution'])) {
        foreach ($incidentTracking['statusDistribution'] as $flag => $count) {
            if ($count > 0) {
                $statusLabels[] = $statusMap[$flag] ?? 'Unknown';
                $statusData[] = $count;
            }
        }
    }
    
    $incidentTypeLabels = isset($incidentTracking['incidentTypes']) ? $incidentTracking['incidentTypes']->pluck('type')->map(fn($t) => ucwords(str_replace('_', ' ', $t)))->values()->toArray() : [];
    $incidentTypeData = isset($incidentTracking['incidentTypes']) ? $incidentTracking['incidentTypes']->pluck('count')->values()->toArray() : [];
    $incidentTypeKeys = isset($incidentTracking['incidentTypes']) ? $incidentTracking['incidentTypes']->pluck('type')->values() : [];

    // Patrol type data
    $patrolTypeLabels = isset($patrolAnalytics['patrolByType']) ? $patrolAnalytics['patrolByType']->pluck('type')->toArray() : [];
    $patrolTypeCounts = isset($patrolAnalytics['patrolByType']) ? $patrolAnalytics['patrolByType']->pluck('count')->toArray() : [];
    $patrolTypeDistances = isset($patrolAnalytics['patrolByType']) ? $patrolAnalytics['patrolByType']->pluck('total_distance_km')->toArray() : [];
    
    // Daily trend
    $dailyLabels = isset($patrolAnalytics['dailyTrend']) ? $patrolAnalytics['dailyTrend']->pluck('date')->toArray() : [];
    $dailyCounts = isset($patrolAnalytics['dailyTrend']) ? $patrolAnalytics['dailyTrend']->pluck('patrol_count')->toArray() : [];
    $dailyDistances = isset($patrolAnalytics['dailyTrend']) ? $patrolAnalytics['dailyTrend']->pluck('distance_km')->toArray() : [];
@endphp

<script>
/**
 * Dashboard Global Data Objects
 */
window.incidentTrackingData = {
    statusLabels: {!! json_encode($statusLabels) !!},
    statusData: {!! json_encode($statusData) !!},
    typeLabels: {!! json_encode($incidentTypeLabels) !!},
    typeKeys: {!! json_encode($incidentTypeKeys) !!},
    typeData: {!! json_encode($incidentTypeData) !!}
};

window.patrolAnalyticsData = {
    typeLabels: {!! json_encode($patrolTypeLabels) !!},
    typeCounts: {!! json_encode($patrolTypeCounts) !!},
    typeDistances: {!! json_encode($patrolTypeDistances) !!},
    dailyLabels: {!! json_encode($dailyLabels) !!},
    dailyCounts: {!! json_encode($dailyCounts) !!},
    dailyDistances: {!! json_encode($dailyDistances) !!}
};

@php
    $attendanceTrend = isset($attendanceAnalytics['dailyTrend']) ? $attendanceAnalytics['dailyTrend'] : collect();
    $timeDistribution = isset($timePatterns['hourlyDistribution']) ? $timePatterns['hourlyDistribution'] : collect();
@endphp

window.attendanceData = {
    dailyLabels: {!! json_encode($attendanceTrend->pluck('date')) !!},
    presentData: {!! json_encode($attendanceTrend->pluck('present')) !!},
    lateData: {!! json_encode($attendanceTrend->pluck('late')) !!},
    absentData: {!! json_encode($attendanceTrend->map(fn($item) => 0)) !!}
};

window.timePatternsData = {
    hourlyLabels: {!! json_encode($timeDistribution->pluck('hour')->map(fn($h) => sprintf("%02d:00", $h))) !!},
    hourlyData: {!! json_encode($timeDistribution->pluck('count')) !!}
};

// Re-initialize charts if the function is available
if (typeof window.initializeCharts === 'function') {
    window.initializeCharts();
}

/**
 * Filter Helper
 */
window.getCurrentFilters = function() {
    const range = document.getElementById('rangeSelect')?.value || '';
    const beat = document.getElementById('beatSelect')?.value || '';
    const startDate = document.getElementById('startDateInput')?.value || '';
    const endDate = document.getElementById('endDateInput')?.value || '';
    const user = document.getElementById('userSelect')?.value || '';
    
    let params = new URLSearchParams();
    if (range) params.append('range', range);
    if (beat) params.append('beat', beat);
    if (startDate) params.append('start_date', startDate);
    if (endDate) params.append('end_date', endDate);
    if (user) params.append('user', user);
    
    return params.toString();
};

/**
 * Incident Tracking Functions
 */
window.showIncidentsByType = async function(key, label, extraParams = {}) {
    const modalEl = document.getElementById('incidentTypeModal');
    const title = document.getElementById('incidentTypeModalTitle');
    const body = document.getElementById('incidentTypeListBody');
    
    if (!modalEl || !title || !body) return;

    const cleanKey = (key === undefined || key === null || key === '') ? 'all' : key;
    const cleanLabel = (label === undefined || label === null || label === '' || label === ' Details') ? 'Incident Details' : label;
    
    title.innerText = cleanLabel;
    body.innerHTML = '<tr><td colspan="5" class="text-center py-5"><div class="spinner-border text-info"></div><p class="text-muted mt-2 small">Fetching incidents...</p></td></tr>';
    
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    try {
        const globalFilters = window.getCurrentFilters();
        const baseUrl = "{{ route('incidents.by-type', ['type' => ':type']) }}".replace(':type', encodeURIComponent(cleanKey)).replace(/\/$/, ""); 
        let url = `${baseUrl}?source=patrol_logs&${globalFilters}&`;
        
        Object.keys(extraParams).forEach(k => {
            url += `${k}=${encodeURIComponent(extraParams[k])}&`;
        });
        
        const response = await fetch(url);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const data = await response.json();
        
        if (!data.incidents || data.incidents.length === 0) {
            body.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted small">No incidents found for this selection</td></tr>';
            return;
        }

        body.innerHTML = data.incidents.map((inc, index) => `
            <tr onclick="openIncidentDetail(${inc.id})" style="cursor:pointer" class="hover-bg-info-subtle">
                <td class="ps-3 text-muted small">${index + 1}</td>
                <td><span class="badge bg-light text-info-emphasis border border-info-subtle">${(inc.type || '').replace(/_/g, ' ').toUpperCase()}</span></td>
                <td><div class="fw-bold text-dark small">${inc.guard || 'N/A'}</div></td>
                <td>
                    <div class="small text-muted">${inc.beat_name || 'N/A'}</div>
                    <div class="extra-small text-muted" style="font-size: 0.7rem;">${inc.range_name || ''}</div>
                </td>
                <td class="text-end pe-3">
                    <div class="small fw-bold">${new Date(inc.created_at).toLocaleDateString()}</div>
                    <div class="extra-small text-muted" style="font-size: 0.7rem;">${new Date(inc.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        console.error('Error fetching incidents:', err);
        body.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-5 small">Error: ${err.message}</td></tr>`;
    }
};

window.openIncidentDetail = async function(id) {
    const listModalEl = document.getElementById('incidentTypeModal');
    const existingListModal = bootstrap.Modal.getInstance(listModalEl);
    if (existingListModal) existingListModal.hide();
    
    const detailModalEl = document.getElementById('incidentDetailModal');
    const detailModal = new bootstrap.Modal(detailModalEl);
    const content = document.getElementById('incidentDetailContent');
    
    if (!detailModalEl || !content) return;

    content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" style="width: 3.5rem; height: 3.5rem;"></div><p class="mt-3 text-muted fw-bold">Gathering incident evidence...</p></div>';
    
    if (!id) {
        content.innerHTML = '<div class="alert alert-warning border-0 shadow-sm">Invalid Incident ID. Unable to load details.</div>';
        return;
    }
    
    detailModal.show();

    const statusMap = {
        0: { label: 'Pending (Supervisor)', color: 'warning', icon: 'bi-hourglass-split' },
        1: { label: 'Resolved', color: 'success', icon: 'bi-check-circle-fill' },
        2: { label: 'Ignored', color: 'secondary', icon: 'bi-slash-circle' },
        3: { label: 'Escalated (Admin)', color: 'danger', icon: 'bi-arrow-up-circle-fill' },
        4: { label: 'Pending (Admin)', color: 'warning', icon: 'bi-person-badge-fill' },
        5: { label: 'Escalated (Client)', color: 'danger', icon: 'bi-megaphone-fill' },
        6: { label: 'Reverted', color: 'info', icon: 'bi-arrow-left-right' }
    };

    try {
        const baseUrl = "{{ route('incidents.details', ['id' => ':id']) }}".replace(':id', id);
        const response = await fetch(baseUrl);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const data = await response.json();
        
        if (data.error) throw new Error(data.error);

        const inc = data.incident || {};
        const comments = data.comments || [];
        const typeText = (inc.type || 'Incident').replace(/_/g, ' ').toUpperCase();
        const status = statusMap[inc.statusFlag] || { label: 'Pending', color: 'warning', icon: 'bi-hourglass' };
        
        const getRelativeTime = (dateString) => {
            const date = new Date(dateString);
            const now = new Date();
            const diffInSeconds = Math.floor((now - date) / 1000);
            if (diffInSeconds < 60) return 'Just now';
            const diffInMinutes = Math.floor(diffInSeconds / 60);
            if (diffInMinutes < 60) return `${diffInMinutes}m ago`;
            const diffInHours = Math.floor(diffInMinutes / 60);
            if (diffInHours < 24) return `${diffInHours}h ago`;
            const diffInDays = Math.floor(diffInHours / 24);
            if (diffInDays < 30) return `${diffInDays}d ago`;
            return date.toLocaleDateString();
        };

        content.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex gap-2">
                    <span class="badge rounded-pill bg-${status.color} shadow-sm px-3 py-2 d-flex align-items-center">
                        <i class="bi ${status.icon} me-2"></i> ${status.label}
                    </span>
                    <span class="badge rounded-pill bg-dark shadow-sm px-3 py-2 d-flex align-items-center text-white">
                        <i class="bi ${inc.session === 'Night' ? 'bi-moon-stars-fill' : 'bi-sun-fill'} me-2"></i> ${inc.session || 'Day'} Patrol
                    </span>
                </div>
            </div>
            <div class="card border-0 bg-white shadow-sm overflow-hidden mb-4" style="border-radius: 12px;">
                <div class="p-4 border-bottom bg-light-subtle d-flex justify-content-between align-items-start">
                    <div>
                        <h4 class="fw-bold mb-1 text-primary"><i class="bi bi-shield-exclamation me-2"></i> ${typeText}</h4>
                        <div class="d-flex align-items-center mt-2">
                            <i class="bi bi-geo-alt-fill text-danger me-2"></i>
                            <span class="text-dark fw-semibold">${inc.beat_name || 'Unknown Beat'}</span>
                        </div>
                    </div>
                </div>
                <div class="p-4">
                    <img src="${inc.photo ? (inc.photo.startsWith('data:') ? inc.photo : '/storage/'+inc.photo) : 'https://placehold.co/600x400?text=No+Evidence'}" class="img-fluid rounded border mb-3">
                    <p class="mb-0 text-dark">${inc.notes || 'No notes.'}</p>
                </div>
            </div>
        `;
    } catch (err) {
        content.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
    }
};

/**
 * Patrol Analytics Functions
 */
window.showPatrolsByType = async function(type, titleLabel) {
    const modalEl = document.getElementById('patrolTypeModal');
    const title = document.getElementById('patrolTypeModalTitle');
    const body = document.getElementById('patrolTypeListBody');
    
    if (!modalEl || !title || !body) return;

    title.innerText = titleLabel || `Patrols: ${type}`;
    body.innerHTML = '<tr><td colspan="5" class="text-center py-5"><div class="spinner-border text-success"></div><p class="text-muted mt-2 small">Loading patrol data...</p></td></tr>';
    
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    try {
        const globalFilters = window.getCurrentFilters();
        const response = await fetch(`/api/patrols-by-type?type=${encodeURIComponent(type)}&${globalFilters}`);
        const data = await response.json();
        
        if (!data.patrols || data.patrols.length === 0) {
            body.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted small"><i class="bi bi-info-circle me-1"></i>No patrols found for this selection</td></tr>';
            return;
        }

        body.innerHTML = data.patrols.map((patrol, index) => `
            <tr>
                <td class="ps-3 text-muted small">${index + 1}</td>
                <td><div class="fw-bold text-dark small">${patrol.guard_name || 'Unknown'}</div></td>
                <td><div class="small fw-bold text-dark">${patrol.site_name || 'N/A'}</div></td>
                <td class="text-center"><span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">${patrol.distance_km} km</span></td>
                <td class="text-end pe-3"><div class="small text-dark fw-bold">${patrol.formatted_start}</div></td>
            </tr>
        `).join('');
    } catch (error) {
        console.error('Error fetching patrols:', error);
        body.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-danger small">Error loading data.</td></tr>';
    }
};

/**
 * KPI Card Modal Listeners and Fetchers
 */
window.initKpiListeners = function() {
    // Active Guards
    document.getElementById('activeGuardsModal')?.addEventListener('show.bs.modal', function() {
        const listContainer = document.getElementById('activeGuardsList');
        if (!listContainer) return;

        listContainer.innerHTML = '<tr><td colspan="5" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>';
        fetch(`/api/active-guards?${window.getCurrentFilters()}`)
            .then(r => r.json())
            .then(data => {
                let html = '';
                if (data.guards && data.guards.length > 0) {
                    data.guards.forEach((g, i) => {
                        html += `<tr><td class="ps-3 text-muted">${i+1}</td><td><a href="#" class="guard-name-link text-decoration-none fw-bold" data-guard-id="${g.id}">${g.name}</a></td><td>${g.phone||'N/A'}</td><td>${g.email||'N/A'}</td><td class="text-center"><span class="badge bg-success-subtle text-success">Active</span></td></tr>`;
                    });
                } else html = '<tr><td colspan="5" class="text-center py-5">No records</td></tr>';
                listContainer.innerHTML = html;
            });
    });

    // Patrol Analytics Overview
    document.getElementById('patrolAnalyticsModal')?.addEventListener('show.bs.modal', function() {
        const typeTable = document.getElementById('modalPatrolTypeTable');
        const sitesTable = document.getElementById('modalTopSitesTable');
        if (!typeTable || !sitesTable) return;

        typeTable.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading breakdown...</td></tr>';
        sitesTable.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-success me-2"></div>Loading site rankings...</td></tr>';

        fetch(`/api/patrol-analytics?${window.getCurrentFilters()}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Update Summary Cards
                    document.getElementById('modalTotalPatrols').innerText = data.summary.totalPatrols.toLocaleString();
                    document.getElementById('modalCompletedPatrols').innerText = data.summary.completedPatrols.toLocaleString();
                    document.getElementById('modalOngoingPatrols').innerText = data.summary.ongoingPatrols.toLocaleString();
                    document.getElementById('modalTotalDistance').innerText = data.summary.totalDistance.toLocaleString() + ' km';
                    
                    // Update Progress Bar
                    document.getElementById('modalCompletionRateBadge').innerText = data.summary.completionRate + '%';
                    const progressBar = document.getElementById('modalCompletionProgressBar');
                    progressBar.style.width = data.summary.completionRate + '%';
                    progressBar.setAttribute('aria-valuenow', data.summary.completionRate);

                    // Day/Night
                    document.getElementById('modalDayPatrols').innerText = (data.dayNight?.day || 0).toLocaleString();
                    document.getElementById('modalNightPatrols').innerText = (data.dayNight?.night || 0).toLocaleString();

                    // Type Table
                    let typeHtml = '';
                    if (data.breakdown && data.breakdown.length > 0) {
                        data.breakdown.forEach(item => {
                            typeHtml += `<tr><td class="ps-3 py-2 fw-bold text-dark">${item.type}</td><td class="text-center py-2"><span class="badge bg-primary-subtle text-primary rounded-pill">${item.count}</span></td><td class="text-end pe-3 py-2 text-muted fw-bold">${parseFloat(item.distance || 0).toFixed(2)} km</td></tr>`;
                        });
                    } else typeHtml = '<tr><td colspan="3" class="text-center py-4 text-muted small">No data</td></tr>';
                    typeTable.innerHTML = typeHtml;

                    // Sites Table
                    let sitesHtml = '';
                    if (data.topSites && data.topSites.length > 0) {
                        data.topSites.forEach((item, i) => {
                            sitesHtml += `<tr><td class="ps-3 text-muted py-2">${i+1}</td><td class="py-2 text-dark fw-bold">${item.site_name}</td><td class="text-end pe-3 py-2 text-success fw-bold">${parseFloat(item.total_distance_km || 0).toFixed(2)} km</td></tr>`;
                        });
                    } else sitesHtml = '<tr><td colspan="3" class="text-center py-4 text-muted small">No data</td></tr>';
                    sitesTable.innerHTML = sitesHtml;
                }
            });
    });

    // Resolution Rate Details
    document.getElementById('resolutionRateModal')?.addEventListener('show.bs.modal', function() {
        fetch(`/api/incidents-details?${window.getCurrentFilters()}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const stats = data.stats || {};
                    const rate = stats.resolutionRate || 0;
                    
                    document.getElementById('modalResRateTitle').innerText = rate + '%';
                    const progressBar = document.getElementById('modalResRateProgressBar');
                    progressBar.style.width = rate + '%';
                    progressBar.innerText = rate + '%';
                    
                    document.getElementById('modalResRateTotal').innerText = (stats.total || 0).toLocaleString();
                    document.getElementById('modalResRateResolved').innerText = (stats.resolved || 0).toLocaleString();
                    document.getElementById('modalResRatePending').innerText = (stats.pending || 0).toLocaleString();
                    document.getElementById('modalResRateCalculation').innerHTML = `${(stats.resolved || 0).toLocaleString()} ÷ ${(stats.total || 0).toLocaleString()} × 100 = <strong>${rate}%</strong>`;
                }
            });
    });

    // Total Beats
    document.getElementById('totalBeatsModal')?.addEventListener('show.bs.modal', function() {
        const listContainer = document.getElementById('totalBeatsList');
        if (!listContainer) return;
        
        listContainer.innerHTML = '<tr><td colspan="3" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>';
        fetch(`/api/beats-details?${window.getCurrentFilters()}`)
            .then(r => r.json())
            .then(data => {
                let html = '';
                if (data.beats && data.beats.length > 0) {
                    data.beats.forEach((b, i) => {
                        html += `<tr><td class="ps-3 text-muted">${i+1}</td><td><strong>${b.name}</strong></td><td>${b.range_name||'N/A'}</td></tr>`;
                    });
                } else html = '<tr><td colspan="3" class="text-center py-5">No records</td></tr>';
                listContainer.innerHTML = html;
            });
    });

    // Coverage Analytics
    document.getElementById('beatCoverageModal')?.addEventListener('show.bs.modal', function() {
        const gapsList = document.getElementById('coverageGapsList');
        const visitedList = document.getElementById('mostVisitedBeatsList');
        const leastVisitedList = document.getElementById('leastVisitedBeatsList');
        
        if (!gapsList||!visitedList||!leastVisitedList) return;

        [gapsList, visitedList, leastVisitedList].forEach(el => el.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>');

        fetch(`/api/coverage-analysis?${window.getCurrentFilters()}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Summary and Charts updates here if needed (omitted for brevity, assume they handle themselves)
                    
                    // Gaps
                    gapsList.innerHTML = (data.gaps || []).map(s => `
                        <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 border-0 border-bottom">
                            <span class="small fw-bold text-danger">${s.site_name}</span>
                            <span class="badge bg-danger-subtle text-danger rounded-pill">0</span>
                        </div>
                    `).join('') || '<div class="text-success text-center py-3">Perfect Coverage</div>';

                    // Most Visited
                    visitedList.innerHTML = (data.mostPatrolled || []).map(s => `
                        <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 border-0 border-bottom">
                            <span class="small fw-bold">${s.site_name}</span>
                            <span class="badge bg-success-subtle text-success rounded-pill">${s.patrol_count}</span>
                        </div>
                    `).join('') || '<div class="text-muted text-center py-3">No data</div>';

                    // Least Visited
                    leastVisitedList.innerHTML = (data.leastPatrolled || []).map(s => `
                        <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 border-0 border-bottom">
                            <span class="small">${s.site_name}</span>
                            <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill">${s.patrol_count}</span>
                        </div>
                    `).join('') || '<div class="text-muted text-center py-3">No data</div>';
                }
            });
    });

    // Total Distance
    document.getElementById('totalDistanceModal')?.addEventListener('show.bs.modal', function() {
        const list = document.getElementById('totalDistanceList');
        if (!list) return;

        list.innerHTML = '<tr><td colspan="4" class="text-center py-5"><div class="spinner-border text-info"></div></td></tr>';
        fetch(`/api/distance-details?${window.getCurrentFilters()}`)
            .then(r => r.json())
            .then(data => {
                let html = '';
                if (data.breakdown && data.breakdown.length > 0) {
                    data.breakdown.forEach((item, i) => {
                        html += `<tr><td class="ps-3 text-muted small">${i+1}</td><td><div class="fw-bold text-dark small">${item.guard_name}</div></td><td><div class="small text-dark fw-bold">${item.site_name}</div></td><td class="text-end pe-3 fw-bold text-info">${parseFloat(item.total_distance_km).toFixed(2)} km</td></tr>`;
                    });
                } else html = '<tr><td colspan="4" class="text-center py-5 text-muted">No records</td></tr>';
                list.innerHTML = html;
            });
    });

    // Attendance Details
    document.getElementById('attendanceDetailsModal')?.addEventListener('show.bs.modal', function() {
        const list = document.getElementById('attendanceDetailsList');
        if (!list) return;

        list.innerHTML = '<tr><td colspan="4" class="text-center py-5"><div class="spinner-border text-warning"></div></td></tr>';
        fetch(`/api/attendance-details?${window.getCurrentFilters()}`)
            .then(r => r.json())
            .then(data => {
                let html = '';
                const days = data.summary?.days_in_range || 1;
                if (data.breakdown && data.breakdown.length > 0) {
                    data.breakdown.forEach(item => {
                        const pct = (item.present_days / days * 100).toFixed(0);
                        html += `<tr><td class="ps-3"><div class="fw-bold text-dark small">${item.guard_name}</div></td><td><div class="small fw-bold text-dark">${item.site_name}</div></td><td class="text-center"><span class="badge bg-success-subtle text-success">${item.present_days} / ${Math.round(days)} Days</span></td><td class="text-end pe-3 text-dark fw-bold small">${item.late_days} Times</td></tr>`;
                    });
                } else html = '<tr><td colspan="4" class="text-center py-5 text-muted">No records</td></tr>';
                list.innerHTML = html;
            });
    });
};

/**
 * Auto-initialize interactions
 */
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    window.initKpiListeners();
} else {
    document.addEventListener('DOMContentLoaded', window.initKpiListeners);
}

console.log('Dashboard Scripts Consolidated & Loaded');
</script>
