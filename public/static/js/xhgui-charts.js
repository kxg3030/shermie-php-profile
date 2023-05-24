window.Xhgui = window.Xhgui || {};

Xhgui.metricName = function (metric) {
    let map = {
        pmu : "最大内存",
        mu  : "内存使用",
        cpu : "CPU时间",
        wt  : "执行时间",
        epmu: "独立最大内存",
        emu : "独立内存占用",
        ecpu: "独立CPU占用",
        ewt : "独立执行时间"
    };
    if (!map[metric]) {
        return "未知";
    }
    return map[metric];
};

/**
 * Color generator for graphs.
 */
Xhgui.colors = function () {
    let colors = [
        '#59bdd2', // blue
        '#637964', // green
        '#d46245', // red
        '#ffe85e', // yellow
        '#e9814f', // orange
        '#e3b7b3', // pink
        '#b63c71' // purple
    ];
    return d3.scale.ordinal().range(colors);
};

/**
 * Format a date object to a readable string in SQL date format.
 *
 * @param Date date The date to format.
 * @return String Formatted date string.
 */
Xhgui.formatDate = d3.time.format('%y-%m-%d %H:%M:%S');

/**
 * Dynamic date/time format based on date being shown
 */
Xhgui.dateTimeFormat;

Xhgui.formatNumber = function (num, decimalPlaces, unit = "ms") {
    if (decimalPlaces === undefined) {
        decimalPlaces = 3;
    }
    let step = 1000;
    if (unit === "bytes") {
        step = 1024;
    }
    let number    = +num;
    let val       = number.toFixed(decimalPlaces);
    let split     = val.split(/\./);
    let thousands = split[0];
    split[0]      = (thousands / step).toFixed(decimalPlaces);
    return split[0].toString();
};


Xhgui.legend = function (svg, text, height, margin, color) {
    if (!text) {
        return;
    }
    // position on y. 3 offsets descenders.
    let yOffset = height + margin.bottom - 3;

    // calculate the xOffset based on all the other legend.
    // cross fingers that we don't run out of X space.
    let legendGroups = svg.select('.legend-group');
    let xOffset      = 0;

    if (legendGroups[0].length && legendGroups[0][0]) {
        let box = legendGroups[legendGroups.length - 1][0].getBBox();
        // 20 is some margin.
        xOffset = box.x + box.width + 20;
    }

    let group = svg.append('g')
        .attr('class', 'legend-group');

    // Append the legend dot
    group.append('circle')
        .attr('fill', color)
        .attr('r', 3)
        .attr('cx', 0)
        .attr('cy', -5);

    // Add text.
    group.append('text')
        .attr('x', 5)
        .attr('y', 0)
        .text(text);

    // position the group
    group.attr('transform', 'translate(' + xOffset + ', ' + yOffset + ')');
};

/**
 * Bind a tooltip to an element.
 */
Xhgui.tooltip = function (container, options) {
    if (
        !options.formatter ||
        !options.positioner ||
        !options.bindTo
    ) {
        throw new Exception('You need the formatter, positioner & bindTo options.');
    }

    function stop() {
        d3.event.stopPropagation();
    }

    function createTooltip(container) {
        let exists = container.select('#chart-popover'), popover, content;
        if (exists.empty()) {
            popover = container.append('div');
            popover.attr('class', 'popover top').attr('id', 'chart-popover').append('div').attr('class', 'arrow');
            content = popover.append('div').attr('class', 'popover-content');

            container.on('mouseout', stop);
            popover.on('mouseout', stop);
            return {frame: popover, content: content};
        }
        popover = exists;
        content = exists.select('.popover-content');
        return {frame: popover, content: content};
    }

    let tooltip = createTooltip(d3.select(document.body));

    function hide() {
        tooltip.frame.transition().style('opacity', 0);
        d3.select(document).on('mouseout', false);
    }

    options.bindTo.on('mouseover', function (d, i) {
        let top, left, tooltipHeight, tooltipWidth, content, position;
        // Get the tooltip content.
        content = options.formatter.call(this, d, i);
        tooltip.content.html(content);
        tooltip.frame.style({display: 'block', opacity: 1});

        // Get the tooltip position.
        position      = options.positioner.call(this, d, i, tooltip);
        tooltipWidth  = parseInt(tooltip.frame.style('width'), 10);
        tooltipHeight = parseInt(tooltip.frame.style('height'), 10);

        let containerNode = container.node();
        // Recalculate based on width/height of tooltip.
        // arrow is 10x10, so 7 & 5 are magic numbers
        top  = containerNode.offsetTop + position.y - (tooltipHeight / 2) - 7;
        left = containerNode.offsetLeft + position.x - (tooltipWidth / 2);

        tooltip.frame.style({top: top + 'px', left: left + 'px'});

        d3.select(document).on('mouseout', hide);
    });
};

/**
 * Create a pie chart.
 *
 * @param selector container The container for the chart
 * @param array data The data list with name, value keys.
 * @param object options
 */
Xhgui.piechart = function (container, data, options) {
    options    = options || {};
    let height = options.height || 400,
        width  = options.width || 400,
        radius = Math.min(width, height) / 2;

    let arc = d3.svg.arc()
        .outerRadius(radius - 10)
        .innerRadius(0);

    let pie = d3.layout.pie()
        .sort(null)
        .value(function (d) {
            return d.value;
        });

    let color = Xhgui.colors();

    container = d3.select(container);

    let svg = container.append('svg')
        .attr('width', width)
        .attr('height', height)
        .append('g')
        .attr('transform', "translate(" + width / 2 + "," + height / 2 + ")");

    let g = svg.selectAll('.chart-arc')
        .data(pie(data))
        .enter().append('g')
        .attr('class', 'chart-arc');

    g.append('path')
        .attr('d', arc)
        .style('fill', function (d) {
            return color(d.data.value);
        });

    Xhgui.tooltip(container, {
        bindTo    : g,
        positioner: function (d, i) {
            let position, sliceX, sliceY;

            position = arc.centroid(d, i);

            // Recalculate base on outer transform.
            sliceX = position[0] + (height / 2);
            sliceY = position[1] + (width / 2);
            return {x: sliceX, y: sliceY};
        },
        formatter : function (d, i) {
            let label = '<strong>' + d.data.name +
                        '</strong><br />' +
                        Xhgui.formatNumber(d.data.value, 0) + options.postfix;
            return label;
        }
    });
};

/**
 * Create a column chart.
 *
 * @param selector container The container for the chart
 * @param array data The data list with name, value keys.
 * @param object options
 */
Xhgui.columnchart = function (container, data, options) {
    options    = options || {};
    let height = options.height || 400,
        width  = options.width || 400,
        margin = {top: 20, right: 20, bottom: 30, left: 50};

    let y = d3.scale.linear()
        .range([height, 0]);

    let x = d3.scale.ordinal()
        .rangeRoundBands([0, width], 0.1);

    let yAxis = d3.svg.axis()
        .scale(y)
        .tickFormat(d3.format('2s'))
        .tickSize(6, 6, 0)
        .orient("left");

    let xAxis = d3.svg.axis()
        .scale(x)
        .tickSize(6, 6, 0)
        .orient("bottom");

    container = d3.select(container);

    let svg = container.append("svg")
        .attr("width", width + margin.left + margin.right)
        .attr("height", height + margin.top + margin.bottom)
        .append("g")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    x.domain(data.map(function (d, i) {
        return i + 1;
    }));
    y.domain([0, d3.max(data, function (d) {
        return d.value;
    })]);

    svg.append("g")
        .attr("class", "chart-axis x-axis")
        .attr("transform", "translate(0," + height + ")")
        .call(xAxis);

    svg.append("g")
        .attr("class", "chart-axis y-axis")
        .call(yAxis);

    svg.selectAll('.chart-bar')
        .data(data)
        .enter().append("rect")
        .attr("class", "chart-bar")
        .attr("x", function (d) {
            return x(d.value);
        })
        .attr("width", x.rangeBand())
        .attr("y", function (d) {
            return y(d.value);
        })
        .attr("height", function (d) {
            return height - y(d.value);
        });

    Xhgui.tooltip(container, {
        bindTo    : svg.selectAll('.chart-bar'),
        positioner: function (d, i) {
            let position, x, y;
            position = this.getBBox();

            // Recalculate base on outer transform.
            // 7 is a magic number. It offsets the arrow.
            x = position.x + (position.width * 1.5) - 7;
            return {x: x, y: position.y};
        },
        formatter : function (d, i) {
            let label = '<strong>' + d.name +
                        '</strong><br />' +
                        Xhgui.formatNumber(d.value, 0) + options.postfix;
            return label;
        }
    });
};

/**
 * Creates a single or multiseries line graph with tooltips.
 *
 * Options:
 *
 * - xAxis - The key to use for the x-axis.
 * - series - An array of the keys used for the series data.
 * - title - The chart title.
 * - legend - An array of legends for each series.
 * - postfix - A string to append to the tooltip for each datapoint.
 * - height - The height of the chart.
 *
 * @param string container Selector to the container for the graph
 * @param array data The data to graph. Should be an array of objects. Each
 * object should contain a key for each element in `options.series`.
 * @param object options The options to use. Needs to define xAxis & series
 */
Xhgui.linegraph = function (container, data, options) {
    options = options || {};
    if (!options.xAxis || !options.series) {
        throw new Exception('You need to define series & xAxis');
    }

    container = d3.select(container);

    let margin    = {top: 30, right: 20, bottom: 40, left: 50},
        height    = options.height || (parseInt(container.style('height'), 10) - margin.top - margin.bottom),
        width     = options.width || (parseInt(container.style('width'), 10) - margin.left - margin.right),
        lastIndex = data.length - 1;

    if (!Array.isArray(options.series)) {
        options.series = [options.series];
    }

    // Convert X-axis key into date objects.
    data = data.map(function (d) {
        if (d[options.xAxis] instanceof Date) {
            return d;
        }

        let col = d[options.xAxis];

        // If it contains a colon it has a timestamp also
        if (col.indexOf(":") !== -1) {
            let dateTimeParts    = col.split(" ");
            let dateParts        = dateTimeParts[0].split('-');
            let timeParts        = dateTimeParts[1].split(':');
            Xhgui.dateTimeFormat = '%H:%M:%S'
        } else {
            let dateParts        = d[options.xAxis].split('-');
            let timeParts        = [0, 0, 0];
            Xhgui.dateTimeFormat = '%y-%m-%d'
        }

        let date         = new Date(dateParts[0], dateParts[1] - 1, dateParts[2], timeParts[0], timeParts[1], timeParts[2]);
        d[options.xAxis] = date;
        return d;
    });

    let xRange = d3.extent(data, function (d) {
        return d[options.xAxis];
    });

    let x = d3.scale.linear()
        .range([0, width])
        .domain(xRange);

    let xSpread = xRange[1] - xRange[0];

    // Get the maxes for all series.
    let maxes = [];
    options.series.forEach(function (key) {
        let max = d3.max(data, function (d) {
            return d[key];
        });
        maxes.push(max);
    });
    let yDomain = [0, d3.max(maxes)];

    let y = d3.scale.linear()
        .range([height, 0])
        .domain(yDomain);

    let dateFormatter = d3.time.format(Xhgui.dateTimeFormat);

    let xAxis = d3.svg.axis()
        .scale(x)
        .tickFormat(function (d) {
            return dateFormatter(new Date(d));
        })
        .tickSize(9, 6, 0)
        .orient('bottom');

    // Only show as many ticks as will fit.
    // Assume tick labels are 70px wide
    xAxis.ticks(Math.abs(x.range()[1] - x.range()[0]) / 70);

    let yAxis = d3.svg.axis()
        .scale(y)
        .tickFormat(d3.format('2s'))
        .tickSize(6, 6, 0)
        .orient("left");

    // If there are going to be too
    // many ticks (they are ~18px tall)
    // make fewer ticks.
    if (height / 18 < 10) {
        yAxis = yAxis.ticks(height / 18);
    }

    let svg = container.append("svg")
        .attr("width", width + margin.left + margin.right)
        .attr("height", height + margin.top + margin.bottom)
        .append("g")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    // Add X-axis
    svg.append("g")
        .attr("class", "chart-axis x-axis")
        .attr("transform", "translate(0," + height + ")")
        .call(xAxis);

    // Add Y-axis
    svg.append("g")
        .attr("class", "chart-axis y-axis")
        .call(yAxis);

    if (options.title) {
        svg.append('text')
            .attr('y', 10)
            .attr('x', width / 2)
            .style('text-anchor', 'middle')
            .text(options.title)
            .attr('transform', 'translate(0, ' + (margin.top * -1) + ')');
    }

    let colors = Xhgui.colors();

    function drawLine(i, series) {
        let line = d3.svg.line()
            .x(function (d) {
                return x(d[options.xAxis]);
            })
            .y(function (d) {
                return y(d[series]);
            });

        svg.append("path")
            .datum(data)
            .attr("class", "chart-line")
            .style('stroke', function (d) {
                return colors(i);
            })
            .attr("d", line);
    }

    function drawDots(i, series) {
        let g = svg.append('g')
            .attr('class', 'chart-dots');

        let circle = g.selectAll('circle')
            .data(data)
            .enter()
            .append('circle')
            .style('fill', colors(i))
            .attr('cx', function (d) {
                return x(d[options.xAxis]);
            })
            .attr('cy', function (d) {
                return y(d[series]);
            })
            .attr('r', 4);

        Xhgui.tooltip(container, {
            bindTo    : circle,
            positioner: function (d, i) {
                let x, y;

                x = this.cx.baseVal.value;
                y = this.cy.baseVal.value;
                x += margin.left - 7;
                y += 7;
                return {x: x, y: y};
            },

            formatter: function (d, i) {
                let value  = '';
                let xValue = d[options.xAxis];
                value += '<strong>';
                if (xValue instanceof Date) {
                    value += Xhgui.formatDate(xValue);
                } else {
                    value += xValue;
                }
                value += '</strong>';
                value += '<br />';
                value += Xhgui.formatNumber(d[series], 0);
                if (options.postfix) {
                    value += options.postfix;
                }
                return value;
            }
        });
    }

    for (let i = 0, len = options.series.length; i < len; i++) {
        let series = options.series[i];
        drawLine(i, series);
        drawDots(i, series);
        if (options.legend) {
            Xhgui.legend(
                svg,
                options.legend[i],
                height,
                margin,
                colors(i)
            );
        }
    }
};
