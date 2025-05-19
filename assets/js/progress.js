document.addEventListener('DOMContentLoaded', function () {
    const progressCanvas = document.getElementById('progressChart');
    if (progressCanvas) {
        fetch('../data/progress.json')
            .then(response => response.json())
            .then(data => {
                const ctx = progressCanvas.getContext('2d');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Progress (%)',
                            data: data.progress,
                            backgroundColor: 'rgba(54, 162, 235, 0.5)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100
                            }
                        }
                    }
                });
            })
            .catch(err => console.error('Error loading progress data:', err));
    }
});
