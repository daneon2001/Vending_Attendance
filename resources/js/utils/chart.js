export function hasChartData(dataset) {
    if (!dataset || !Array.isArray(dataset.labels) || dataset.labels.length === 0) {
        return false;
    }

    if (!Array.isArray(dataset.datasets) || dataset.datasets.length === 0) {
        return false;
    }

    return dataset.datasets.some(
        (ds) =>
            Array.isArray(ds.data) &&
            ds.data.some((value) => {
                const num = Number(value);
                return Number.isFinite(num);
            }),
    );
}
