<template>
  <ModalWrapper :show="show">
    <DropIn @after-leave="$emit('close')">
      <div v-if="show" v-clickaway="close" ref="modal" class="inline-block w-full max-w-md align-middle bg-white dark:bg-gray-600 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8" role="dialog" aria-modal="true">
        <div class="px-4 pt-5 pb-4 sm:p-6">
          <Label for="student-search-term">{{ __('Seach for student') }}</Label>
          <Input v-model="term" id="student-search-term" :placeholder="__('Search by name, email or student number')" type="search" autofocus />

          <label for="invoice-student-status">{{ __('Students to search') }}</label>
          <select id="invoice-student-status" v-model="status" class="block w-full my-3">
            <option value="all">{{ __('All students and applicants') }}</option>
            <option value="applicants">{{ __('Applicants') }}</option>
            <option value="enrolled">{{ __('Enrolled') }}</option>
          </select>
          <Loader v-if="fetchingStudents" class="pb-0" />

          <FadeIn>
            <div v-if="students.length > 0" class="mt-4 w-full space-y-1">
              <a
                v-for="student in students"
                :key="student.id"
                @click.prevent="selected(student)"
                href="#"
                class="flex justify-between text-sm w-full rounded items-center py-2 px-4 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
              >
                <div>
                  {{ student.full_name }} <span class="text-gray-500 dark:text-gray-400">({{ student.student_number }})</span>
                </div>
                <div>
                  {{ student.grade_level_formatted }}
                </div>
              </a>
            </div>
          </FadeIn>
        </div>
      </div>
    </DropIn>
  </ModalWrapper>
</template>

<script>
import { defineComponent, nextTick, onMounted, onUnmounted, ref, watchEffect } from 'vue'
import { disableBodyScroll, clearAllBodyScrollLocks } from 'body-scroll-lock'
import clickaway from '@/directives/clickaway'
import fetchesStudents from '@/composition/fetchesStudents.js'
import Input from '@/components/forms/Input.vue'
import Label from '@/components/forms/Label.vue'
import FadeIn from '@/components/transitions/FadeIn.vue'
import Loader from '@/components/Loader.vue'
import debounce from 'lodash/debounce'
import ModalWrapper from '@/components/modals/ModalWrapper.vue'
import DropIn from '@/components/transitions/DropIn.vue'

export default defineComponent({
  directives: {
    clickaway,
  },
  components: {
    DropIn,
    ModalWrapper,
    Loader,
    FadeIn,
    Input,
    Label,
  },
  emits: ['close', 'selected'],

  setup (props, { emit }) {
    const show = ref(false)
    const term = ref('')
    const status = ref('all')
    const { students, search, fetchingStudents } = fetchesStudents()
    const modal = ref(null)
    const close = () => {
      show.value = false
    }
    const selected = student => {
      emit('selected', student)
      close()
    }
    const listener = (e) => {
      if (e.key === 'Escape') {
        e.stopPropagation()
        close()
      }
    }
    const runSearch = debounce(() => {
      search({ s: term.value, status: status.value })
    }, 500)

    watchEffect(() => {
      const selectedStatus = status.value
      if (term.value || selectedStatus === 'applicants') {
        runSearch()
      } else {
        students.value = []
      }
    })

    watchEffect(() => {
      if (show.value) {
        nextTick(() => {
          disableBodyScroll(modal.value)
        })
      } else {
        clearAllBodyScrollLocks()
      }
    })

    onMounted(() => {
      document.addEventListener('keydown', listener)
      show.value = true
    })

    onUnmounted(() => {
      document.removeEventListener('keydown', listener)
    })

    return {
      show,
      modal,
      close,
      students,
      term,
      status,
      selected,
      fetchingStudents,
    }
  },
})
</script>
