package site.fortunetttech.customer.ui.profile;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.customer.data.repository.ProfileRepository;

@ScopeMetadata
@QualifierMetadata
@DaggerGenerated
@Generated(
    value = "dagger.internal.codegen.ComponentProcessor",
    comments = "https://dagger.dev"
)
@SuppressWarnings({
    "unchecked",
    "rawtypes",
    "KotlinInternal",
    "KotlinInternalInJava",
    "cast"
})
public final class ProfileViewModel_Factory implements Factory<ProfileViewModel> {
  private final Provider<ProfileRepository> repoProvider;

  public ProfileViewModel_Factory(Provider<ProfileRepository> repoProvider) {
    this.repoProvider = repoProvider;
  }

  @Override
  public ProfileViewModel get() {
    return newInstance(repoProvider.get());
  }

  public static ProfileViewModel_Factory create(Provider<ProfileRepository> repoProvider) {
    return new ProfileViewModel_Factory(repoProvider);
  }

  public static ProfileViewModel newInstance(ProfileRepository repo) {
    return new ProfileViewModel(repo);
  }
}
