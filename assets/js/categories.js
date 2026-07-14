(function () {
    'use strict';

    var list = document.getElementById('category-sort-list');
    if (!list) {
        return;
    }

    var status = document.getElementById('category-sort-status');
    var endpoint = list.getAttribute('data-endpoint');
    var csrfToken = list.getAttribute('data-csrf-token');
    var savedOrder = getOrder();
    var saving = false;
    var saveQueued = false;
    var dragState = null;
    var lastTouchStart = 0;

    function getRows() {
        return Array.prototype.slice.call(list.querySelectorAll('tr[data-category-id]'));
    }

    function getOrder() {
        return getRows().map(function (row) {
            return parseInt(row.getAttribute('data-category-id'), 10);
        });
    }

    function ordersMatch(first, second) {
        return first.length === second.length && first.every(function (value, index) {
            return value === second[index];
        });
    }

    function setStatus(message, type) {
        if (!status) {
            return;
        }
        status.textContent = message;
        status.className = 'category-sort-status' + (type ? ' category-sort-status-' + type : '');
    }

    function restoreOrder(order) {
        var rowsById = {};
        getRows().forEach(function (row) {
            rowsById[row.getAttribute('data-category-id')] = row;
        });
        order.forEach(function (id) {
            if (rowsById[String(id)]) {
                list.appendChild(rowsById[String(id)]);
            }
        });
    }

    function saveOrder() {
        if (saving) {
            saveQueued = true;
            return;
        }

        var currentOrder = getOrder();
        if (ordersMatch(currentOrder, savedOrder)) {
            return;
        }

        saving = true;
        setStatus('Saving order…', 'saving');

        var formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('action', 'reorder');
        formData.append('category_ids', JSON.stringify(currentOrder));

        fetch(endpoint, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'The category order could not be saved.');
                    }
                    return data;
                });
            })
            .then(function (data) {
                savedOrder = currentOrder.slice();
                setStatus(data.message || 'Category order saved.', 'success');
                window.setTimeout(function () {
                    if (status && status.textContent === (data.message || 'Category order saved.')) {
                        setStatus('', '');
                    }
                }, 2500);
            })
            .catch(function (error) {
                restoreOrder(savedOrder);
                setStatus(error.message || 'The category order could not be saved.', 'error');
            })
            .finally(function () {
                saving = false;
                if (saveQueued) {
                    saveQueued = false;
                    saveOrder();
                }
            });
    }

    function dragClientY(event) {
        if (event.touches && event.touches.length) {
            return event.touches[0].clientY;
        }
        if (event.changedTouches && event.changedTouches.length) {
            return event.changedTouches[0].clientY;
        }
        return event.clientY;
    }

    function moveDraggedRow(clientY) {
        if (!dragState) {
            return;
        }

        var activeRow = dragState.row;
        var otherRows = getRows().filter(function (row) {
            return row !== activeRow;
        });
        var beforeRow = null;

        for (var i = 0; i < otherRows.length; i += 1) {
            var rect = otherRows[i].getBoundingClientRect();
            if (clientY < rect.top + (rect.height / 2)) {
                beforeRow = otherRows[i];
                break;
            }
        }

        if (beforeRow) {
            list.insertBefore(activeRow, beforeRow);
        } else {
            list.appendChild(activeRow);
        }

        dragState.moved = !ordersMatch(getOrder(), dragState.startOrder);

        var edgeSize = 70;
        if (clientY < edgeSize) {
            window.scrollBy(0, -12);
        } else if (clientY > window.innerHeight - edgeSize) {
            window.scrollBy(0, 12);
        }
    }

    function onMouseMove(event) {
        if (!dragState || dragState.inputType !== 'mouse') {
            return;
        }
        event.preventDefault();
        moveDraggedRow(dragClientY(event));
    }

    function onMouseUp(event) {
        if (!dragState || dragState.inputType !== 'mouse') {
            return;
        }
        event.preventDefault();
        finishDrag(false);
    }

    function onTouchMove(event) {
        if (!dragState || dragState.inputType !== 'touch') {
            return;
        }
        event.preventDefault();
        moveDraggedRow(dragClientY(event));
    }

    function onTouchEnd(event) {
        if (!dragState || dragState.inputType !== 'touch') {
            return;
        }
        event.preventDefault();
        finishDrag(false);
    }

    function onTouchCancel() {
        if (dragState && dragState.inputType === 'touch') {
            finishDrag(true);
        }
    }

    function addDragListeners(inputType) {
        if (inputType === 'touch') {
            document.addEventListener('touchmove', onTouchMove, { passive: false });
            document.addEventListener('touchend', onTouchEnd, { passive: false });
            document.addEventListener('touchcancel', onTouchCancel, { passive: false });
        } else {
            document.addEventListener('mousemove', onMouseMove, false);
            document.addEventListener('mouseup', onMouseUp, false);
        }
    }

    function removeDragListeners() {
        document.removeEventListener('mousemove', onMouseMove, false);
        document.removeEventListener('mouseup', onMouseUp, false);
        document.removeEventListener('touchmove', onTouchMove, false);
        document.removeEventListener('touchend', onTouchEnd, false);
        document.removeEventListener('touchcancel', onTouchCancel, false);
    }

    function beginDrag(row, handle, inputType, event) {
        if (dragState) {
            return;
        }

        dragState = {
            row: row,
            handle: handle,
            inputType: inputType,
            startOrder: getOrder(),
            moved: false
        };

        row.classList.add('category-sort-dragging');
        handle.setAttribute('aria-grabbed', 'true');
        document.body.classList.add('category-sort-active');
        addDragListeners(inputType);
        event.preventDefault();
    }

    function finishDrag(cancelled) {
        if (!dragState) {
            return;
        }

        var completedDrag = dragState;
        removeDragListeners();
        completedDrag.row.classList.remove('category-sort-dragging');
        completedDrag.handle.setAttribute('aria-grabbed', 'false');
        document.body.classList.remove('category-sort-active');
        dragState = null;

        if (cancelled) {
            restoreOrder(completedDrag.startOrder);
        } else if (completedDrag.moved) {
            saveOrder();
        }
    }

    getRows().forEach(function (row) {
        var handle = row.querySelector('.drag-handle');
        if (!handle) {
            return;
        }

        handle.setAttribute('aria-grabbed', 'false');

        handle.addEventListener('mousedown', function (event) {
            if (event.button !== 0 || Date.now() - lastTouchStart < 800) {
                return;
            }
            beginDrag(row, handle, 'mouse', event);
        });

        handle.addEventListener('touchstart', function (event) {
            lastTouchStart = Date.now();
            beginDrag(row, handle, 'touch', event);
        }, { passive: false });

        handle.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
                return;
            }

            var sibling = event.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;
            if (!sibling || !sibling.hasAttribute('data-category-id')) {
                return;
            }

            if (event.key === 'ArrowUp') {
                list.insertBefore(row, sibling);
            } else {
                list.insertBefore(sibling, row);
            }
            event.preventDefault();
            handle.focus();
            saveOrder();
        });
    });

    Array.prototype.slice.call(document.querySelectorAll('.category-delete-button')).forEach(function (button) {
        button.addEventListener('click', function (event) {
            var categoryName = button.getAttribute('data-category-name') || 'this category';
            if (!window.confirm('Delete the category "' + categoryName + '"? This cannot be undone.')) {
                event.preventDefault();
            }
        });
    });
})();
