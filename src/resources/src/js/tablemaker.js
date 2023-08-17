// ==========================================================================

// Table Maker Plugin for Craft CMS
// Author: Verbb - https://verbb.io/

// ==========================================================================

(function($) {

Craft.TableMaker = Garnish.Base.extend({
    columnsTableId: null,
    rowsTableId: null,
    columnsTableName: null,
    rowsTableName: null,
    columnsTableInputPath: null,
    rowsTableInputPath: null,
    columns: null,
    rows: null,
    columnSettings: null,
    fieldId: null,

    columnsTable: null,
    rowsTable: null,

    $columnsTable: null,
    $rowsTable: null,
    $input: null,

    redactorConfig: null,

    init: function(fieldId, columnsTableId, rowsTableId, columnsTableName, rowsTableName, columns, rows, columnSettings, redactorConfig) {
        this.columnsTableId = columnsTableId;
        this.rowsTableId = rowsTableId;

        this.columnsTableName = columnsTableName;
        this.rowsTableName = rowsTableName;

        this.columnsTableInputPath = this.columnsTableName.replace(/]/g, '').split('[');
        this.rowsTableInputPath = this.rowsTableName.replace(/]/g, '').split('[');

        this.columns = columns;
        this.rows = rows;

        this.columnSettings = columnSettings;
        this.fieldId = fieldId

        this.$columnsTable = $('#' + this.columnsTableId);
        this.$rowsTable = $('#' + this.rowsTableId);
        this.$input = $('#' + fieldId + '-field').find('input.table-maker-field');

        this.redactorConfig = redactorConfig;

        // set up columns table
        this.initColumnsTable();

        // set up rows table
        this.initRowsTable();

        // make the data blob
        this.makeDataBlob();
    },

    onColumnsAddRow: function() {
        this.bindColumnsTableChanges();
        this.reconstructRowsTable();
    },

    onRowsAddRow: function($tr) {
      this.bindRowsTableTextChanges();
      this.makeDataBlob();
      this.initRedactor($tr);
    },

    bindColumnsTableChanges: function() {
        // text changes
        var $textareas = this.columnsTable.$tbody.find('textarea');
        this.removeListener($textareas, 'textchange');

        this.addListener($textareas, 'textchange', $.debounce(250, function(e) {
            this.reconstructRowsTable(e);
        }));

        // select changes
        var $selects = this.columnsTable.$tbody.find('select');
        this.removeListener($selects, 'change');

        this.addListener($selects, 'change', $.debounce(250, function(e) {
            this.reconstructRowsTable(e);
        }));
    },

    bindRowsTableTextChanges: function() {
        // console.log('bindRowsTableTextChanges')
        var $textareas = this.rowsTable.$tbody.find('textarea');
        this.removeListener($textareas, 'textchange');

        this.addListener($textareas, 'textchange', $.debounce(250, function(e) {
            this.makeDataBlob(e);
        }));
    },

    initColumnsTable: function() {
        this.columnsTable = new Craft.EditableTable(this.columnsTableId, this.columnsTableName, this.columnSettings, {
            rowIdPrefix: 'col',
            allowAdd: true,
            allowDelete: true,
            allowReorder: true,
            onAddRow: $.proxy(this, 'onColumnsAddRow'),
            onDeleteRow: $.proxy(this, 'reconstructRowsTable'),
        });

        this.bindColumnsTableChanges();

        this.columnsTable.sorter.settings.onSortChange = $.proxy(this, 'reconstructRowsTable');
    },

    initRowsTable: function(columns) {
        this.rowsTable = new Craft.EditableTable(this.rowsTableId, this.rowsTableName, this.columns, {
            rowIdPrefix: 'row',
            allowAdd: true,
            allowDelete: true,
            allowReorder: true,
            onAddRow: $.proxy(this, 'onRowsAddRow'),
            onDeleteRow: $.proxy(this, 'makeDataBlob'),
        });

        this.bindRowsTableTextChanges();

        this.rowsTable.sorter.settings.onSortChange = $.proxy(this, 'makeDataBlob');
    },

    reconstructRowsTable: function() {
        this.getDataFromTables();

        // prep table
        var tableHtml = '<thead>' +
             '<tr>';

        // re-do columns of rowsTable
        for (var colId in this.columns) {
            if (this.columns[colId].hasOwnProperty('fieldType')) {
              this.columns[colId].type = this.columns[colId].fieldType;
            } else {
              // force type of col to be textual
              this.columns[colId].type = 'singleline';
            }

          tableHtml += '<th scope="col" class="header">'+(this.columns[colId].heading ? this.columns[colId].heading : '&nbsp;') + '</th>';
        }

        tableHtml += '<th class="header" colspan="2"></th>' +
             '</tr>' +
         '</thead>';

        var $table = $('<table/>', {
            id: this.rowsTableId,
            'class': 'editable fullwidth',
        }).append(tableHtml);

        var $tbody = $('<tbody/>').appendTo($table);

        var trs = [];

        // merge in the current rows content
        for (var rowId in this.rows) {
            if (!this.rows.hasOwnProperty(rowId)) {
                continue;
            }

            trs.push(Craft.EditableTable.createRow(rowId, this.columns, this.rowsTableName, this.rows[rowId]).appendTo($tbody));
        }

        this.rowsTable.$table.replaceWith($table);
        this.rowsTable.destroy();
        delete this.rowsTable;
        this.initRowsTable(this.columns);
        this.makeDataBlob();

      for (var i = 0; i<trs.length;i++)
      {
          this.initRedactor(trs[i]);
      }
    },

    getDataFromTables: function() {
        // get data out from the tables
        var columns = Craft.expandPostArray(Garnish.getPostData(this.columnsTable.$tbody));
        var rows = Craft.expandPostArray(Garnish.getPostData(this.rowsTable.$tbody));

        // travel down the input paths to find where the data we’re interested in actually is
        if (!$.isEmptyObject(columns)) {
            for (var i = 0; i < this.columnsTableInputPath.length; i++) {
                var key = this.columnsTableInputPath[i];
                columns = columns[key];
            }
        }

        this.columns = columns;

        if (!$.isEmptyObject(rows)) {
            for (var i = 0; i < this.rowsTableInputPath.length; i++) {
                var key = this.rowsTableInputPath[i];
                rows = rows[key];
            }
        }

        this.rows = rows;
    },

    makeDataBlob: function() {
        this.getDataFromTables();

        var dataBlob = {
            'columns' : this.columns,
            'rows' : this.rows
        };

        this.$input.val(JSON.stringify(dataBlob));
    },

    initRedactor: function($tr)
    {

      var rowNum = $tr.data('id');

      if (this.rows.hasOwnProperty(rowNum)) {
        var textareas = $tr.find('.html-cell textarea');

        for (var k = 0; k < textareas.length; k++) {
          var handle = 'textarea-' + rowNum + '-' + k;

          textareas[k].id = handle;

          var config = this.redactorConfig;
          config['id'] = handle;

          new Craft.RedactorInput(config);
        }
      }
    }

});

/*
 * jQuery throttle / debounce - v1.1 - 3/7/2010
 * http://benalman.com/projects/jquery-throttle-debounce-plugin/
 *
 * Copyright (c) 2010 "Cowboy" Ben Alman
 * Dual licensed under the MIT and GPL licenses.
 * http://benalman.com/about/license/
 */
(function(b,c){var $=b.jQuery||b.Cowboy||(b.Cowboy={}),a;$.throttle=a=function(e,f,j,i){var h,d=0;if(typeof f!=="boolean"){i=j;j=f;f=c}function g(){var o=this,m=+new Date()-d,n=arguments;function l(){d=+new Date();j.apply(o,n)}function k(){h=c}if(i&&!h){l()}h&&clearTimeout(h);if(i===c&&m>e){l()}else{if(f!==true){h=setTimeout(i?k:l,i===c?e-m:e)}}}if($.guid){g.guid=j.guid=j.guid||$.guid++}return g};$.debounce=function(d,e,f){return f===c?a(d,e,false):a(d,f,e!==false)}})(this);

})(jQuery);
