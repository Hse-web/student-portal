document.addEventListener('DOMContentLoaded', function () {
    const homeworkTableBody = document.getElementById('homeworkTableBody');
    
    if (homeworkTableBody) {
        fetch('../data/homework.json')
            .then(response => {
                if (!response.ok) {
                    throw new Error("Network response was not ok: " + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                homeworkTableBody.innerHTML = ''; // Clear existing data
                data.forEach(item => {
                    const row = document.createElement('tr');
                    row.innerHTML = `<td>${item.title}</td>
                                     <td>${item.dueDate}</td>
                                     <td>${item.description}</td>`;
                    homeworkTableBody.appendChild(row);
                });
            })
            .catch(err => console.error('Error fetching homework data:', err));
    }
});
