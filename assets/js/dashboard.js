document.addEventListener('DOMContentLoaded', function () {
    console.log("dashboard.js loaded");

    // Load Attendance using Fetch API
    const attendanceBtn = document.getElementById('loadAttendance');
    const attendanceTableBody = document.getElementById('attendanceTableBody');

    if (attendanceBtn) {
        attendanceBtn.addEventListener('click', function () {
            console.log("Load Attendance button clicked");
            fetch('../data/attendance.json')
                .then(response => {
                    if (!response.ok) {
                        throw new Error("Network response was not ok: " + response.statusText);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log("Attendance data received:", data);
                    // Clear any existing rows
                    attendanceTableBody.innerHTML = '';
                    // Create and append rows for each attendance record
                    data.forEach(item => {
                        const row = document.createElement('tr');
                        row.innerHTML = `<td>${item.date}</td><td>${item.status}</td>`;
                        attendanceTableBody.appendChild(row);
                    });
                })
                .catch(err => console.error('Error fetching attendance data:', err));
        });
    }

    // Modal Interaction Example using Bootstrap modal API
    const showModalBtn = document.getElementById('showModalBtn');
    const modalEl = document.getElementById('exampleModal');
    if (showModalBtn && modalEl) {
        showModalBtn.addEventListener('click', function () {
            console.log("Show Modal button clicked");
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        });
    }
});
