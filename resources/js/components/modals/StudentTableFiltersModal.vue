<template>
  <Modal
    @close="modalClosed"
    @action="applyFilters"
    :action-text="__('Apply filters')"
  >
    <div class="mt-4 space-y-5">
      <InputWrap>
        <Label for="perPage">{{ __('Results per page') }}</Label>
        <Select id="perPage" v-model="localFilters.perPage">
          <option value="10">10</option>
          <option value="25">25</option>
          <option value="50">50</option>
          <option value="100">100</option>
        </Select>
      </InputWrap>

      <InputWrap>
        <Label>{{ __('Grade levels') }}</Label>
        <div class="grid grid-cols-6 gap-2">
          <CheckboxWrapper v-for="grade in school.grade_levels">
            <Checkbox v-model:checked="localFilters.grades" :value="grade" />
            <CheckboxText>{{ displayShortGrade(grade) }}</CheckboxText>
          </CheckboxWrapper>
        </div>
      </InputWrap>

      <InputWrap v-if="allTags.length > 0">
        <Label>{{ __('Tags') }}</Label>
        <div class="grid grid-cols-3 gap-2">
          <CheckboxWrapper v-for="tag in allTags">
            <Checkbox v-model:checked="localFilters.tags" :value="tag.name" />
            <CheckboxText>
              <span class="flex items-center space-x-2">
                <span class="h-2 w-2 rounded-full" :class="colors[tag.color]" aria-hidden="true"></span>
                <span>{{ tag.name }}</span>
              </span>
            </CheckboxText>
          </CheckboxWrapper>
        </div>
      </InputWrap>

      <InputWrap>
        <Label for="enrolled">{{ __('Enrollment Status') }}</Label>
        <Select id="enrolled" v-model="localFilters.status">
          <option value="applicants">{{ __('Applicants') }}</option>
          <option value="enrolled">{{ __('Enrolled') }}</option>
          <option value="withdrawn">{{ __('Not enrolled') }}</option>
          <option value="all">{{ __('Enrolled and not enrolled') }}</option>
        </Select>
      </InputWrap>
    </div>
  </Modal>
</template>

<script>
import { defineComponent, inject, reactive } from 'vue'
import Modal from '@/components/Modal.vue'
import InputWrap from '@/components/forms/InputWrap.vue'
import Label from '@/components/forms/Label.vue'
import Select from '@/components/forms/Select.vue'
import Checkbox from '@/components/forms/Checkbox.vue'
import CheckboxText from '@/components/forms/CheckboxText.vue'
import CheckboxWrapper from '@/components/forms/CheckboxWrapper.vue'
import displaysGrades from '@/composition/displaysGrades.js'
import fetchesStudentTags from '@/composition/fetchesStudentTags.js'
import tagColorKey from '@/composition/tagColorKey.js'

export default defineComponent({
  emits: ['close', 'apply'],
  components: {
    CheckboxWrapper,
    CheckboxText,
    Checkbox,
    Select,
    Label,
    InputWrap,
    Modal
  },

  props: {
    filters: Object,
    school: Object,
  },

  setup (props, { emit }) {
    const $http = inject('$http')
    const modalClosed = () => {
      emit('close')
    }
    const applyFilters = () => {
      localFilters.page = 1
      emit('apply', localFilters)
    }
    const localFilters = reactive(Object.assign({}, props.filters))
    const { displayShortGrade } = displaysGrades()
    const { allTags, fetchAllTags } = fetchesStudentTags()
    const colors = tagColorKey()
    fetchAllTags()

    return {
      modalClosed,
      localFilters,
      applyFilters,
      displayShortGrade,
      allTags,
      colors,
    }
  }
})
</script>
