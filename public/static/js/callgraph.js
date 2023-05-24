Xhgui.callgraph = function (container, data, options) {
    // Color scale
    let colors = d3.scale.linear().domain([0, 100]).range(['#fff', '#CC0033']);

    let textSize = d3.scale.linear().domain([0, 100]).range([0.5, 3]);

    let $ = layui.jquery;

    // Generate the style props for a node and its label
    let nodeStyle      = function (node) {
        let ratio = node.value / data.total * 100;
        return 'fill: ' + colors(ratio) + ';'
    };
    let nodeLabelStyle = function (node) {
        let ratio = node.value / data.total * 100;
        return 'font-size: ' + textSize(ratio) + 'em;'
    }
    // Get a data hash for a given node.
    let nodeData       = function (node) {
        let ratio = node.value / data.total * 100;
        return {
            metric   : data.metric,
            value    : node.value,
            ratio    : ratio,
            callCount: node.callCount,
        };
    }
    let el             = d3.select(container), width = parseInt(el.style('width'), 10), height = 1000;
    let svg            = d3.select(container).append('svg').attr('class', 'callgraph').attr('width', width).attr('height', height);
    let g              = new dagreD3.Digraph();
    for (let i = 0, len = data.nodes.length; i < len; i++) {
        let node = data.nodes[i];

        g.addNode(node.name, {
            label     : node.name + ' - ' + data.metric + ' ' + (node.value / 1000).toFixed(2),
            style     : nodeStyle(node),
            labelStyle: nodeLabelStyle(node),
            data      : nodeData(node)
        });
    }
    for (let i = 0, len = data.links.length; i < len; i++) {
        let edge = data.links[i];
        let word = "次调用";
        g.addEdge(
            null,
            edge.source,
            edge.target,
            {label: edge.callCount + word}
        );
    }

    // Lay out the graph more tightly than the defaults.
    let layout = dagreD3.layout().nodeSep(30).rankSep(30).rankDir("TB");

    // Render the graph.
    let renderer = new dagreD3.Renderer().layout(layout);

    let oldEdge = renderer.drawEdgePaths();
    renderer.drawEdgePaths(function (g, root) {
        let node = oldEdge(g, root);
        node.attr('data-value', function (d) {
            return d;
        });
        return node;
    });

    let oldNode = renderer.drawNodes();
    renderer.drawNodes(function (g, root) {
        let node = oldNode(g, root);
        node.attr('data-value', function (d) {
            return d.replace(/\\/g, '_');
        });
        return node;
    });

    // Capture zoom object so tooltips can be hidden
    let zoom;
    renderer.zoom(function (graph, svg) {
        zoom = d3.behavior.zoom().on('zoom', function () {
            svg.attr('transform', 'translate(' + d3.event.translate + ')scale(' + d3.event.scale + ')');
        });
        return zoom;
    });

    renderer.run(g, svg);

    let hideTooltip = function (e) {
        $('.popover').hide();
        return true;
    };
    // Hide tooltip on zoom
    zoom.on('zoom.tooltip', hideTooltip);

    // Bind click events for function calls
    let nodes = svg.selectAll('.node');
    nodes.on('click', function (d, edge) {
        nodes.classed('active', false);
        d3.select(this).classed('active', true);
        let params = {
            symbol   : d,
            threshold: options.threshold,
            metric   : options.metric
        };
        // let xhr    = $.get(options.shortUrl + '&' + $.param(params))
        // xhr.done(function (response) {
        //     details.addClass('active').find('.details-content').html(response);
        //     Xhgui.tableSort(details.find('.table-sort'));
        // });
        highlightSubtree(d);
    });

    // Set tooltips on boxes.
    Xhgui.tooltip(el, {
        bindTo    : nodes,
        positioner: function (d, i, tooltip) {
            let position = this.getBoundingClientRect();
            let height   = parseInt(tooltip.frame.style('height'), 10);
            position     = {
                x: position.left + (position.width / 2) - 20,
                y: position.top + window.scrollY - el.node().offsetTop - (height / 2),
            };
            return position;
        },
        formatter : function (d, i) {
            let data  = g.node(d).data;
            let units = 'ms';
            if (data.metric.indexOf('mu') !== -1) {
                units = 'bytes';
            }
            let ratio  = data.ratio.toFixed(2);
            let value  = (data.value / 1e3).toFixed(2);
            let metric = Xhgui.metricName(data.metric);
            return `
<strong>${d}</strong>
<br>
<strong>${metric}：${ratio}%(${value}${units})</strong>
<br>
<strong>调用次数：${data.callCount}次</strong>`;
        }
    });

    // Collects and iterates the subtree of nodes/edges and highlights them.
    let highlightSubtree = function (root) {
        let i, len;
        let subtree = [root];
        let nodes   = [root];
        while (nodes.length > 0) {
            let node       = nodes.shift();
            let childNodes = g.successors(node);
            if (childNodes.length === 0) {
                break;
            }
            // Append into the 'queue' so we can collect *all the nodes*
            nodes = nodes.concat(childNodes);

            // Collect the entire subtree so we can find and highlight edges.
            subtree = subtree.concat(childNodes);
        }

        let edges = [];
        // Find the outgoing edges for each node in the subtree.
        let node;
        for (i = 0, len = subtree.length; i < len; i++) {
            node  = subtree[i];
            edges = edges.concat(g.outEdges(node));
        }

        // Clear width.
        svg.selectAll('g.edgePath path').style('stroke-width', 1);

        // Highlight each edge in the subtree.
        for (i = 0, len = edges.length; i < len; i++) {
            svg.select('g.edgePath[data-value=' + edges[i] + '] path').style('stroke-width', 8).style('stroke', "#1e9fff");
        }
    };


    // Approximately center an element in the canvas.
    let centerElement = function (rect) {
        let zoomEl   = svg.select('.zoom');
        let position = rect[0].getBoundingClientRect();

        // Get the box center so we can center the center.
        let scale     = zoom.scale();
        let offset    = zoom.translate();
        let translate = [
            offset[0] - position.left - (position.width / 2) + (window.innerWidth / 2),
            offset[1] - position.top - (position.height / 2) + (window.innerHeight / 2)
        ];

        zoom.translate(translate);
        zoomEl.transition().duration(750).attr('transform', 'translate(' + translate[0] + ',' + translate[1] + ')scale(' + scale + ')');
    };

    // Setup details view.
    let details = $(options.detailView);
    details.find('.button-close').on('click', function () {
        details.removeClass('active');
        details.find('.details-content').empty();
        return false;
    });

    // Child symbol links move graph around.
    details.on('click', '.child-symbol a', function (e) {
        let symbol = $(this).attr('title').replace(/\\/g, '_');
        let rect   = $('[data-value="' + symbol + '"]');

        // Not in the DOM; cancel.
        if (!rect.length) {
            return false;
        }
        hideTooltip();
        centerElement(rect);

        // Simulate a click as d3 and jQuery handle events differently.
        let evt = document.createEvent("MouseEvents");
        evt.initMouseEvent("click", true, true, window, 0, 0, 0, 0, 0, false, false, false, false, 0, null);
        rect[0].dispatchEvent(evt);
        return false;
    });

};
