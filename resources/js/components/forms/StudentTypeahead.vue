<template>
  <Combobox as="div" v-slot="{ open }" class="relative" v-model="localValue">
    <ComboboxInput
      @change="query = $event.target.value"
      :class="input"
      :display-value="(student) => student.name || ''"
      :id="id"
      :placeholder="__('Search by name, student number or email')"
    />
    <DropIn>
      <ComboboxOptions
        v-slot="{ open }"
        class="absolute z-10 origin-top-left mt-2 w-full space-y-1 rounded-md shadow-lg bg-white dark:bg-gray-700 ring-1 ring-black ring-opacity-5 focus:outline-none"
        :class="{
          'p-1': open && students.length > 0,
        }"
      >
        <ComboboxOption
          as="template"
          v-for="student in students"
          :key="student.uuid"
          :value="student"
          v-slot="{ active, selected }"
        >
          <li
            :class="[
              (active || selected) ? classes.active : classes.inactive,
              classes.always,
              'flex space-x-2',
            ]"
          >
            <span>{{ student.full_name }} ({{ student.grade_level_short_formatted }})</span>
          </li>
        </ComboboxOption>
      </ComboboxOptions>
    </DropIn>
  </Combobox>
</template>

<script>
import { defineComponent, inject, ref, watch } from 'vue'
import { Combobox, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/vue'
import hasModelValue from '@/composition/hasModelValue.js'
import menuItemClasses from '@/composition/menuItemClasses.js'
import InvoiceStatusBadge from '@/components/InvoiceStatusBadge.vue'
import inputClasses from '@/composition/inputClasses.js'
import debounce from 'lodash/debounce'
import DropIn from '@/components/transitions/DropIn.vue'

export default defineComponent({
  components: {
    DropIn,
    InvoiceStatusBadge,
    Combobox,
    ComboboxInput,
    ComboboxOption,
    ComboboxOptions,
  },
  props: {
    modelValue: [Object],
    id: String,
    exclude: [String, Array],
  },
  emits: ['update:modelValue'],

  setup (props, { emit }) {
    const { input } = inputClasses()
    const { localValue } = hasModelValue(props, emit)
    const classes = menuItemClasses()
    const $http = inject('$http')
    const fetching = ref(false)
    const query = ref('')
    const students = ref([])
    const search = debounce(async (term) => {
       const { data } = await $http.post('/search/students', {
         s: term,
         status: 'all',
         exclude: props.exclude,
      })
      students.value = data
    }, 250)
    watch(query, search)

    return {
      students,
      input,
      localValue,
      classes,
      query,
      fetching,
    }
  }
})
</script>
